<?php declare(strict_types=1);

namespace Amp\Websocket;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\Websocket\Compression\CompressionContext;
use Amp\Websocket\Parser\Rfc6455FrameCompilerFactory;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\Parser\WebsocketFrameCompilerFactory;
use Amp\Websocket\Parser\WebsocketParserFactory;
use Revolt\EventLoop;

/**
 * @implements \IteratorAggregate<int, WebsocketMessage>
 */
final class Rfc6455Client implements WebsocketClient, \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;

    public const DEFAULT_FRAME_SPLIT_THRESHOLD = 32768; // 32KB
    public const DEFAULT_CLOSE_PERIOD = 3;

    /** @var ConcurrentIterator<WebsocketMessage> */
    private readonly ConcurrentIterator $messageIterator;

    private readonly Internal\Rfc6455FrameHandler $frameHandler;

    private readonly Internal\WebsocketClientMetadata $metadata;

    private ?Future $lastWrite = null;

    /**
     * @param bool $masked True for client, false for server.
     */
    public function __construct(
        private readonly Socket $socket,
        bool $masked,
        WebsocketParserFactory $parserFactory = new Rfc6455ParserFactory(),
        WebsocketFrameCompilerFactory $compilerFactory = new Rfc6455FrameCompilerFactory(),
        ?CompressionContext $compressionContext = null,
        ?WebsocketHeartbeatQueue $heartbeatQueue = null,
        ?WebsocketRateLimit $rateLimit = null,
        private readonly int $frameSplitThreshold = self::DEFAULT_FRAME_SPLIT_THRESHOLD,
        float $closePeriod = self::DEFAULT_CLOSE_PERIOD,
    ) {
        $this->metadata = new Internal\WebsocketClientMetadata($compressionContext !== null);

        $this->frameHandler = new Internal\Rfc6455FrameHandler(
            socket: $this->socket,
            frameCompiler: $compilerFactory->createFrameCompiler($masked, $compressionContext),
            heartbeatQueue: $heartbeatQueue,
            rateLimit: $rateLimit,
            metadata: $this->metadata,
            closePeriod: $closePeriod,
        );

        $this->messageIterator = $this->frameHandler->iterate();

        $heartbeatQueue?->insert($this);

        EventLoop::queue(
            $this->frameHandler->read(...),
            $parserFactory->createParser($this->frameHandler, $masked, $compressionContext),
        );
    }

    public function __destruct()
    {
        if ($this->metadata->isClosed()) {
            return;
        }

        $frameHandler = $this->frameHandler;
        $lastWrite = $this->lastWrite ?? Future::complete();
        $lastWrite->finally(static fn () => $frameHandler->close(WebsocketCloseCode::GOING_AWAY))->ignore();
    }

    public function receive(?Cancellation $cancellation = null): ?WebsocketMessage
    {
        return $this->messageIterator->continue($cancellation)
            ? $this->messageIterator->getValue()
            : null;
    }

    public function getIterator(): \Traversable
    {
        while ($message = $this->receive()) {
            yield $message;
        }
    }

    public function getId(): int
    {
        return $this->metadata->id;
    }

    public function getUnansweredPingCount(): int
    {
        return $this->metadata->pingCount - $this->metadata->pongCount;
    }

    public function getLocalAddress(): SocketAddress
    {
        return $this->socket->getLocalAddress();
    }

    public function getRemoteAddress(): SocketAddress
    {
        return $this->socket->getRemoteAddress();
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return $this->socket->getTlsInfo();
    }

    public function getCloseCode(): ?int
    {
        return $this->metadata->closeCode;
    }

    public function getCloseReason(): ?string
    {
        return $this->metadata->closeReason;
    }

    public function isClosedByPeer(): ?bool
    {
        return $this->metadata->closedByPeer;
    }

    public function isCompressionEnabled(): bool
    {
        return $this->metadata->compressionEnabled;
    }

    public function getStat(WebsocketClientStatKey $key): int
    {
        return match ($key) {
            WebsocketClientStatKey::BytesRead => $this->metadata->bytesRead,
            WebsocketClientStatKey::BytesSent => $this->metadata->bytesSent,
            WebsocketClientStatKey::FramesRead => $this->metadata->framesRead,
            WebsocketClientStatKey::FramesSent => $this->metadata->framesSent,
            WebsocketClientStatKey::MessagesRead => $this->metadata->messagesRead,
            WebsocketClientStatKey::MessagesSent => $this->metadata->messagesSent,
            WebsocketClientStatKey::PingCount => $this->metadata->pingCount,
            WebsocketClientStatKey::PongCount => $this->metadata->pongCount,
        };
    }

    public function getLastEventTime(WebsocketClientEventKey $key): int
    {
        return match ($key) {
            WebsocketClientEventKey::ConnectedAt => $this->metadata->connectedAt,
            WebsocketClientEventKey::ClosedAt => $this->metadata->closedAt,
            WebsocketClientEventKey::LastReadAt => $this->metadata->lastReadAt,
            WebsocketClientEventKey::LastSentAt => $this->metadata->lastSentAt,
            WebsocketClientEventKey::LastDataReadAt => $this->metadata->lastDataReadAt,
            WebsocketClientEventKey::LastDataSentAt => $this->metadata->lastDataSentAt,
            WebsocketClientEventKey::LastHeartbeatAt => $this->metadata->lastHeartbeatAt,
        };
    }

    public function sendText(string $data): void
    {
        \assert((bool) \preg_match('//u', $data), 'Text data must be UTF-8');
        $this->pushData(WebsocketFrameType::Text, $data);
    }

    public function sendBinary(string $data): void
    {
        $this->pushData(WebsocketFrameType::Binary, $data);
    }

    public function streamText(ReadableStream $stream): void
    {
        $this->pushStream(WebsocketFrameType::Text, $stream);
    }

    public function streamBinary(ReadableStream $stream): void
    {
        $this->pushStream(WebsocketFrameType::Binary, $stream);
    }

    public function ping(): void
    {
        ++$this->metadata->pingCount;
        $this->frameHandler->write(WebsocketFrameType::Ping, (string) $this->metadata->pingCount);
    }

    private function pushData(WebsocketFrameType $frameType, string $data): void
    {
        if ($this->lastWrite || \strlen($data) > $this->frameSplitThreshold) {
            // Treat as a stream if another stream is pending or if splitting the data into multiple frames.
            $this->pushStream($frameType, new ReadableBuffer($data));
            return;
        }

        // The majority of messages can be sent with a single frame.
        $this->sendData($frameType, $data);
    }

    private function sendData(WebsocketFrameType $frameType, string $data): void
    {
        ++$this->metadata->messagesSent;
        $this->metadata->lastDataSentAt = \time();

        try {
            $this->frameHandler->write($frameType, $data);
        } catch (\Throwable $exception) {
            $code = WebsocketCloseCode::ABNORMAL_CLOSE;
            $reason = 'Writing to the client failed';
            $this->close($code, $reason);
            throw new WebsocketClosedException('Client unexpectedly closed', $code, $reason, $exception);
        }
    }

    private function pushStream(WebsocketFrameType $frameType, ReadableStream $stream): void
    {
        $this->lastWrite ??= Future::complete();

        // Setting $this->lastWrite will force subsequent sends to queue until this stream has ended.
        $this->lastWrite = $thisWrite = $this->lastWrite->map(
            function () use (&$thisWrite, $stream, $frameType): void {
                try {
                    $this->sendStream($stream, $frameType);
                } finally {
                    // Null the reference to this coroutine if no other writes have been made so subsequent
                    // writes do not have to await a future.
                    if ($this->lastWrite === $thisWrite) {
                        $this->lastWrite = null;
                    }
                }
            }
        );

        $this->lastWrite->await();
    }

    private function sendStream(ReadableStream $stream, WebsocketFrameType $frameType): void
    {
        ++$this->metadata->messagesSent;
        $this->metadata->lastDataSentAt = \time();

        try {
            $chunk = $stream->read();

            if ($chunk === null) {
                $this->frameHandler->write($frameType, '');
                return;
            }

            do {
                $buffer = $chunk;

                // Perform another read to avoid sending an empty frame on stream end.
                $chunk = $stream->read();

                $bufferedLength = \strlen($buffer);
                if ($bufferedLength === 0) {
                    continue;
                }

                if ($bufferedLength > $this->frameSplitThreshold) {
                    $splitLength = $bufferedLength;
                    $slices = (int) \ceil($splitLength / $this->frameSplitThreshold);
                    $splitLength = (int) \ceil($splitLength / $slices);

                    for ($i = 0; $i < $slices - 1; ++$i) {
                        $split = \substr($buffer, $splitLength * $i, $splitLength);

                        $this->frameHandler->write($frameType, $split, false);
                        $frameType = WebsocketFrameType::Continuation;
                    }

                    $buffer = \substr($buffer, $splitLength * $i, $splitLength);
                }

                $this->frameHandler->write($frameType, $buffer, $chunk === null);
                $frameType = WebsocketFrameType::Continuation;
            } while ($chunk !== null);
        } catch (StreamException $exception) {
            $code = WebsocketCloseCode::ABNORMAL_CLOSE;
            $reason = 'Writing to the client failed';
            $this->close($code, $reason);
            throw new WebsocketClosedException('Client unexpectedly closed', $code, $reason, $exception);
        } catch (\Throwable $exception) {
            $this->close(WebsocketCloseCode::UNEXPECTED_SERVER_ERROR, 'Error while reading message data');
            throw $exception;
        }
    }

    public function isClosed(): bool
    {
        return $this->metadata->isClosed();
    }

    public function close(int $code = WebsocketCloseCode::NORMAL_CLOSE, string $reason = ''): void
    {
        $this->frameHandler->close($code, $reason);
        $this->lastWrite = null;
    }

    public function onClose(\Closure $onClose): void
    {
        $metadata = $this->metadata;
        $this->socket->onClose(static fn () => $onClose(
            $metadata->id,
            $metadata->closeCode ?? WebsocketCloseCode::ABNORMAL_CLOSE,
            $metadata->closeReason ?? 'Connection closed unexpectedly',
            $metadata->closedByPeer,
        ));
    }
}
