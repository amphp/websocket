<?php declare(strict_types=1);

namespace Amp\Websocket\Internal;

use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\DisposedException;
use Amp\Pipeline\Queue;
use Amp\Socket\Socket;
use Amp\TimeoutCancellation;
use Amp\Websocket\CloseCode;
use Amp\Websocket\ClosedException;
use Amp\Websocket\Compression\CompressionContext;
use Amp\Websocket\HeartbeatQueue;
use Amp\Websocket\Opcode;
use Amp\Websocket\Parser\ParserException;
use Amp\Websocket\Parser\WebsocketFrameHandler;
use Amp\Websocket\Parser\WebsocketParser;
use Amp\Websocket\Parser\WebsocketParserFactory;
use Amp\Websocket\RateLimiter;
use Amp\Websocket\WebsocketMessage;
use function Amp\async;

/** @internal */
final class Rfc6455FrameHandler implements WebsocketFrameHandler
{
    use ForbidCloning;
    use ForbidSerialization;

    private ?DeferredFuture $closeDeferred;

    /** @var Queue<WebsocketMessage> */
    private readonly Queue $messageQueue;

    /** @var Queue<string>|null */
    private ?Queue $currentMessageQueue = null;

    private readonly WebsocketParser $parser;

    public function __construct(
        private readonly Socket $socket,
        bool $masked,
        WebsocketParserFactory $parserFactory,
        ?CompressionContext $compressionContext,
        private readonly ?HeartbeatQueue $heartbeatQueue,
        private readonly ?RateLimiter $rateLimiter,
        private readonly WebsocketClientMetadata $metadata,
        private readonly float $closePeriod,
    ) {
        $this->closeDeferred = new DeferredFuture();
        $this->messageQueue = new Queue();

        $this->parser = $parserFactory->createParser($this, $masked, $compressionContext);
    }

    public function iterate(): ConcurrentIterator
    {
        return $this->messageQueue->iterate();
    }

    public function read(): void
    {
        try {
            while (($chunk = $this->socket->read()) !== null) {
                if ($chunk === '') {
                    continue;
                }

                $this->metadata->lastReadAt = \time();
                $this->metadata->bytesRead += \strlen($chunk);

                $this->heartbeatQueue?->update($this->metadata->id);
                $this->rateLimiter?->notifyBytesReceived($this->metadata->id, \strlen($chunk));

                $this->parser->push($chunk);

                $chunk = ''; // Free memory from last chunk read.
            }
        } catch (ParserException $exception) {
            $message = $exception->getMessage();
            $code = $exception->getCode();
        } catch (\Throwable $exception) {
            $message = 'TCP connection closed with exception: ' . $exception->getMessage();
        } finally {
            $this->parser->cancel();
            $this->heartbeatQueue?->remove($this->metadata->id);
        }

        $this->closeDeferred?->complete();
        $this->closeDeferred = null;

        if (!$this->metadata->isClosed()) {
            $this->metadata->closedByPeer = true;
            $this->close(
                $code ?? CloseCode::ABNORMAL_CLOSE,
                $message ?? 'TCP connection closed unexpectedly',
            );
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

        if (!$this->currentMessageQueue) {
            if ($opcode === Opcode::Continuation) {
                $this->close(
                    CloseCode::PROTOCOL_ERROR,
                    'Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY'
                );
                return;
            }

            ++$this->metadata->messagesRead;

            if (!$terminated) {
                $this->currentMessageQueue = new Queue();
            }

            // Avoid holding a reference to the ReadableStream or Message object here so destructors will be invoked
            // if the message is not consumed by the user.
            $this->messageQueue->push(self::createMessage(
                $opcode,
                $this->currentMessageQueue
                    ? new ReadableIterableStream($this->currentMessageQueue->iterate())
                    : $data,
            ));

            if (!$this->currentMessageQueue) {
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
            $this->currentMessageQueue->push($data);
        } catch (DisposedException) {
            // Message disposed, ignore exception.
        }

        if ($terminated) {
            $this->currentMessageQueue->complete();
            $this->currentMessageQueue = null;
        }
    }

    private function onControlFrame(Opcode $opcode, string $data): void
    {
        \assert($opcode->isControlFrame());

        // Close already completed, so ignore any further data from the parser.
        if ($this->metadata->isClosed() && $this->closeDeferred === null) {
            return;
        }

        switch ($opcode) {
            case Opcode::Close:
                $this->closeDeferred?->complete();
                $this->closeDeferred = null;

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

    public function write(Opcode $opcode, string $data, bool $isFinal = true): void
    {
        $frame = $this->parser->compileFrame($opcode, $data, $isFinal);

        ++$this->metadata->framesSent;
        $this->metadata->bytesSent += \strlen($frame);
        $this->metadata->lastSentAt = \time();

        $this->socket->write($frame);
    }

    public function close(int $code = CloseCode::NORMAL_CLOSE, string $reason = ''): void
    {
        if ($this->metadata->isClosed()) {
            return;
        }

        \assert($code !== CloseCode::NONE || $reason === '');

        $this->metadata->closedAt = \time();
        $this->metadata->closeCode = $code;
        $this->metadata->closeReason = $reason;

        $this->messageQueue->complete();

        $this->currentMessageQueue?->error(new ClosedException(
            'Connection closed while streaming message body',
            $code,
            $reason,
        ));
        $this->currentMessageQueue = null;

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
            $this->closeDeferred?->getFuture()->await($cancellation);
        } catch (\Throwable) {
            // Failed to write close frame or to receive response frame, but we were disconnecting anyway.
        }

        $this->socket->close();
    }

    private static function createMessage(Opcode $opcode, ReadableStream|string $stream): WebsocketMessage
    {
        if ($opcode === Opcode::Binary) {
            return WebsocketMessage::fromBinary($stream);
        }

        return WebsocketMessage::fromText($stream);
    }
}