<?php declare(strict_types=1);

namespace Amp\Websocket;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\DisposedException;
use Amp\Pipeline\Queue;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\TimeoutCancellation;
use Amp\Websocket\Compression\CompressionContext;
use Amp\Websocket\Parser\ParserException;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\Parser\WebsocketFrameHandler;
use Amp\Websocket\Parser\WebsocketParser;
use Amp\Websocket\Parser\WebsocketParserFactory;
use Revolt\EventLoop;
use function Amp\async;

/**
 * @implements \IteratorAggregate<int, WebsocketMessage>
 */
final class Rfc6455Client implements WebsocketClient, WebsocketFrameHandler, \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;

    public const DEFAULT_FRAME_SPLIT_THRESHOLD = 32768; // 32KB
    public const DEFAULT_CLOSE_PERIOD = 3;

    private ?Future $lastWrite = null;

    /** @var ConcurrentIterator<WebsocketMessage> */
    private readonly ConcurrentIterator $messageIterator;

    private readonly Internal\WebsocketClientQueues $queues;
    private readonly Internal\WebsocketClientMetadata $metadata;

    private readonly WebsocketParser $parser;

    /**
     * @param bool $masked True for client, false for server.
     */
    public function __construct(
        private readonly Socket $socket,
        bool $masked,
        WebsocketParserFactory $parserFactory = new Rfc6455ParserFactory(),
        ?CompressionContext $compressionContext = null,
        private readonly ?HeartbeatQueue $heartbeatQueue = null,
        private readonly ?RateLimiter $rateLimiter = null,
        private readonly int $frameSplitThreshold = self::DEFAULT_FRAME_SPLIT_THRESHOLD,
        private readonly float $closePeriod = self::DEFAULT_CLOSE_PERIOD,
    ) {
        $this->queues = new Internal\WebsocketClientQueues();
        $this->metadata = new Internal\WebsocketClientMetadata($compressionContext !== null);

        $this->messageIterator = $this->queues->messageQueue->iterate();

        $this->parser = $parserFactory->createParser($this, $masked, $compressionContext);

        $this->heartbeatQueue?->insert($this);

        EventLoop::queue(
            $this->read(...),
            socket: $this->socket,
            parser: $this->parser,
            heartbeatQueue: $this->heartbeatQueue,
            rateLimiter: $this->rateLimiter,
            queues: $this->queues,
            metadata: $this->metadata,
        );
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

    private function read(
        Socket $socket,
        WebsocketParser $parser,
        ?HeartbeatQueue $heartbeatQueue,
        ?RateLimiter $rateLimiter,
        Internal\WebsocketClientQueues $queues,
        Internal\WebsocketClientMetadata $metadata,
    ): void {
        try {
            while (($chunk = $socket->read()) !== null) {
                if ($chunk === '') {
                    continue;
                }

                $metadata->lastReadAt = \time();
                $metadata->bytesRead += \strlen($chunk);

                $heartbeatQueue?->update($metadata->id);
                $rateLimiter?->notifyBytesReceived($metadata->id, \strlen($chunk));

                $parser->push($chunk);

                $chunk = ''; // Free memory from last chunk read.
            }
        } catch (ParserException $exception) {
            $message = $exception->getMessage();
            $code = $exception->getCode();
        } catch (\Throwable $exception) {
            $message = 'TCP connection closed with exception: ' . $exception->getMessage();
        } finally {
            $parser->cancel();
            $heartbeatQueue?->remove($metadata->id);
        }

        $this->queues->complete();

        if (!$metadata->isClosed()) {
            $code ??= CloseCode::ABNORMAL_CLOSE;
            $message ??= 'TCP connection closed unexpectedly';

            $metadata->closedByPeer = true;
            $metadata->setClosed($code, $message);
            $queues->close($code, $message);
        }
    }

    public function handleFrame(Opcode $opcode, string $data, bool $isFinal): void
    {
        ++$this->metadata->framesRead;
        $this->rateLimiter?->notifyFramesReceived($this->metadata->id, 1);

        if ($opcode->isControlFrame()) {
            $this->onControlFrame($opcode, $data);
        } else {
            $this->onData($opcode, $data, $isFinal);
        }
    }

    private function onData(Opcode $opcode, string $data, bool $terminated): void
    {
        \assert(!$opcode->isControlFrame());

        $this->metadata->lastDataReadAt = \time();

        // Ignore further data received after initiating close.
        if ($this->metadata->isClosed()) {
            return;
        }

        if (!$this->queues->currentMessageQueue) {
            if ($opcode === Opcode::Continuation) {
                $this->close(
                    CloseCode::PROTOCOL_ERROR,
                    'Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY'
                );
                return;
            }

            ++$this->metadata->messagesRead;

            if (!$terminated) {
                $this->queues->currentMessageQueue = new Queue();
            }

            // Avoid holding a reference to the ReadableStream or Message object here so destructors will be invoked
            // if the message is not consumed by the user.
            $this->queues->messageQueue->push(self::createMessage(
                $opcode,
                $this->queues->currentMessageQueue
                    ? new ReadableIterableStream($this->queues->currentMessageQueue->iterate())
                    : $data,
            ));

            if (!$this->queues->currentMessageQueue) {
                return;
            }
        } elseif ($opcode !== Opcode::Continuation) {
            $this->close(
                CloseCode::PROTOCOL_ERROR,
                'Illegal data type opcode after unfinished previous data type frame; opcode MUST be CONTINUATION',
            );
            return;
        }

        try {
            $this->queues->currentMessageQueue->push($data);
        } catch (DisposedException) {
            // Message disposed, ignore exception.
        }

        if ($terminated) {
            $this->queues->currentMessageQueue->complete();
            $this->queues->currentMessageQueue = null;
        }
    }

    private static function createMessage(Opcode $opcode, ReadableStream|string $stream): WebsocketMessage
    {
        if ($opcode === Opcode::Binary) {
            return WebsocketMessage::fromBinary($stream);
        }

        return WebsocketMessage::fromText($stream);
    }

    private function onControlFrame(Opcode $opcode, string $data): void
    {
        \assert($opcode->isControlFrame());

        // Close already completed, so ignore any further data from the parser.
        if ($this->metadata->isClosed() && $this->queues->isComplete()) {
            return;
        }

        switch ($opcode) {
            case Opcode::Close:
                $this->queues->complete();

                if ($this->metadata->isClosed()) {
                    break;
                }

                $this->metadata->closedByPeer = true;

                $length = \strlen($data);
                if ($length === 0) {
                    $code = CloseCode::NONE;
                    $reason = '';
                } elseif ($length < 2) {
                    $code = CloseCode::PROTOCOL_ERROR;
                    $reason = 'Close code must be two bytes';
                } else {
                    $code = \unpack('n', $data)[1];
                    $reason = \substr($data, 2);

                    if ($code < 1000 // Reserved and unused.
                        || ($code >= 1004 && $code <= 1006) // Should not be sent over wire
                        || ($code >= 1014 && $code <= 1015) // Should not be sent over wire
                        || ($code >= 1016 && $code <= 1999) // Disallowed, reserved for future use
                        || ($code >= 2000 && $code <= 2999) // Disallowed, reserved for Websocket extensions
                        // 3000-3999 allowed, reserved for libraries
                        // 4000-4999 allowed, reserved for applications
                        || $code >= 5000 // >= 5000 invalid
                    ) {
                        $code = CloseCode::PROTOCOL_ERROR;
                        $reason = 'Invalid close code';
                    } elseif (!\preg_match('//u', $reason)) {
                        $code = CloseCode::INCONSISTENT_FRAME_DATA_TYPE;
                        $reason = 'Close reason must be valid UTF-8';
                    }
                }

                $this->close($code, $reason);
                break;

            case Opcode::Ping:
                $this->write(Opcode::Pong, $data);
                break;

            case Opcode::Pong:
                if (!\preg_match('/^[1-9][0-9]*$/', $data)) {
                    // Ignore pong payload that is not an integer.
                    break;
                }

                // We need a min() here, else someone might just send a pong frame with a very high pong count and
                // leave TCP connection in open state... Then we'd accumulate connections which never are cleaned up...
                $this->metadata->pongCount = \min($this->metadata->pingCount, (int) $data);
                $this->metadata->lastHeartbeatAt = \time();
                break;

            default:
                // This should be unreachable
                throw new \Error('Non-control frame opcode: ' . $opcode->name);
        }
    }

    public function send(string $data): void
    {
        \assert((bool) \preg_match('//u', $data), 'Text data must be UTF-8');
        $this->pushData(Opcode::Text, $data);
    }

    public function sendBinary(string $data): void
    {
        $this->pushData(Opcode::Binary, $data);
    }

    public function stream(ReadableStream $stream): void
    {
        $this->pushStream(Opcode::Text, $stream);
    }

    public function streamBinary(ReadableStream $stream): void
    {
        $this->pushStream(Opcode::Binary, $stream);
    }

    public function ping(): void
    {
        ++$this->metadata->pingCount;
        $this->write(Opcode::Ping, (string) $this->metadata->pingCount);
    }

    private function pushData(Opcode $opcode, string $data): void
    {
        if ($this->lastWrite || \strlen($data) > $this->frameSplitThreshold) {
            // Treat as a stream if another stream is pending or if splitting the data into multiple frames.
            $this->pushStream($opcode, new ReadableBuffer($data));
            return;
        }

        // The majority of messages can be sent with a single frame.
        $this->sendData($opcode, $data);
    }

    private function sendData(Opcode $opcode, string $data): void
    {
        ++$this->metadata->messagesSent;
        $this->metadata->lastDataSentAt = \time();

        try {
            $this->write($opcode, $data);
        } catch (\Throwable $exception) {
            $code = CloseCode::ABNORMAL_CLOSE;
            $reason = 'Writing to the client failed';
            $this->close($code, $reason);
            throw new ClosedException('Client unexpectedly closed', $code, $reason, $exception);
        }
    }

    private function pushStream(Opcode $opcode, ReadableStream $stream): void
    {
        $this->lastWrite ??= Future::complete();

        // Setting $this->lastWrite will force subsequent sends to queue until this stream has ended.
        $this->lastWrite = $thisWrite = $this->lastWrite->map(
            function () use (&$thisWrite, $stream, $opcode): void {
                try {
                    $this->sendStream($stream, $opcode);
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

    private function sendStream(ReadableStream $stream, Opcode $opcode): void
    {
        ++$this->metadata->messagesSent;
        $this->metadata->lastDataSentAt = \time();

        try {
            $chunk = $stream->read();

            if ($chunk === null) {
                $this->write($opcode, '');
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

                        $this->write($opcode, $split, false);
                        $opcode = Opcode::Continuation;
                    }

                    $buffer = \substr($buffer, $splitLength * $i, $splitLength);
                }

                $this->write($opcode, $buffer, $chunk === null);
                $opcode = Opcode::Continuation;
            } while ($chunk !== null);
        } catch (StreamException $exception) {
            $code = CloseCode::ABNORMAL_CLOSE;
            $reason = 'Writing to the client failed';
            $this->close($code, $reason);
            throw new ClosedException('Client unexpectedly closed', $code, $reason, $exception);
        } catch (\Throwable $exception) {
            $this->close(CloseCode::UNEXPECTED_SERVER_ERROR, 'Error while reading message data');
            throw $exception;
        }
    }

    private function write(Opcode $opcode, string $data, bool $isFinal = true): void
    {
        $frame = $this->parser->compileFrame($opcode, $data, $isFinal);

        ++$this->metadata->framesSent;
        $this->metadata->bytesSent += \strlen($frame);
        $this->metadata->lastSentAt = \time();

        $this->socket->write($frame);
    }

    public function isClosed(): bool
    {
        return $this->metadata->isClosed();
    }

    public function close(int $code = CloseCode::NORMAL_CLOSE, string $reason = ''): void
    {
        if ($this->metadata->isClosed()) {
            return;
        }

        \assert($code !== CloseCode::NONE || $reason === '');

        $this->metadata->setClosed($code, $reason);
        $this->queues->close($code, $reason);

        if ($this->socket->isClosed()) {
            return;
        }

        try {
            $cancellation = new TimeoutCancellation($this->closePeriod);

            async(
                $this->write(...),
                Opcode::Close,
                $code !== CloseCode::NONE ? \pack('n', $code) . $reason : '',
            )->await($cancellation);

            // Wait for peer close frame for configured number of seconds.
            $this->queues->await($cancellation);
        } catch (\Throwable) {
            // Failed to write close frame or to receive response frame, but we were disconnecting anyway.
        }

        $this->socket->close();
        $this->lastWrite = null;
    }

    public function onClose(\Closure $onClose): void
    {
        $metadata = $this->metadata;
        $this->socket->onClose(static fn () => $onClose(
            $metadata->id,
            $metadata->closeCode ?? CloseCode::ABNORMAL_CLOSE,
            $metadata->closeReason ?? 'Connection closed unexpectedly',
            $metadata->closedByPeer,
        ));
    }
}
