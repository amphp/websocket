<?php declare(strict_types=1);

namespace Amp\Websocket;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Parser\Parser;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\DisposedException;
use Amp\Pipeline\Queue;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\TimeoutCancellation;
use Revolt\EventLoop;
use function Amp\async;

final class Rfc6455Client implements WebsocketClient
{
    public const DEFAULT_TEXT_ONLY = false;
    public const DEFAULT_VALIDATE_UTF8 = true;
    public const DEFAULT_MESSAGE_SIZE_LIMIT = (2 ** 20) * 10; // 10MB
    public const DEFAULT_FRAME_SIZE_LIMIT = 2 ** 20; // 1MB
    public const DEFAULT_FRAME_SPLIT_THRESHOLD = 32768; // 32KB
    public const DEFAULT_CLOSE_PERIOD = 3;

    private ?Future $lastWrite = null;

    /** @var Queue<WebsocketMessage> */
    private readonly Queue $messageEmitter;

    /** @var ConcurrentIterator<WebsocketMessage> */
    private readonly ConcurrentIterator $messageIterator;

    /** @var Queue<string>|null */
    private ?Queue $currentMessageEmitter = null;

    private readonly WebsocketClientMetadata $metadata;

    private ?DeferredFuture $closeDeferred;

    /**
     * @param bool $masked True for client, false for server.
     */
    public function __construct(
        private readonly Socket $socket,
        private readonly bool $masked,
        private readonly ?CompressionContext $compressionContext = null,
        private readonly ?HeartbeatQueue $heartbeatQueue = null,
        private readonly ?RateLimiter $rateLimiter = null,
        private readonly bool $textOnly = self::DEFAULT_TEXT_ONLY,
        private readonly bool $validateUtf8 = self::DEFAULT_VALIDATE_UTF8,
        private readonly int $messageSizeLimit = self::DEFAULT_MESSAGE_SIZE_LIMIT,
        private readonly int $frameSizeLimit = self::DEFAULT_FRAME_SIZE_LIMIT,
        private readonly int $frameSplitThreshold = self::DEFAULT_FRAME_SPLIT_THRESHOLD,
        private readonly float $closePeriod = self::DEFAULT_CLOSE_PERIOD,
    ) {
        $this->closeDeferred = new DeferredFuture;

        $this->messageEmitter = new Queue();
        $this->messageIterator = $this->messageEmitter->iterate();

        $this->metadata = new WebsocketClientMetadata($this->compressionContext !== null);

        $this->heartbeatQueue?->insert($this);

        EventLoop::queue($this->read(...));
    }

    public function receive(?Cancellation $cancellation = null): ?WebsocketMessage
    {
        return $this->messageIterator->continue($cancellation)
            ? $this->messageIterator->getValue()
            : null;
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
        return $this->socket instanceof EncryptableSocket ? $this->socket->getTlsInfo() : null;
    }

    public function getCloseCode(): int
    {
        if ($this->metadata->closeCode === null) {
            throw new \Error('The client has not closed');
        }

        return $this->metadata->closeCode;
    }

    public function getCloseReason(): string
    {
        if ($this->metadata->closeReason === null) {
            throw new \Error('The client has not closed');
        }

        return $this->metadata->closeReason;
    }

    public function isClosedByPeer(): bool
    {
        if (!$this->metadata->closedAt) {
            throw new \Error('The client has not closed');
        }

        return $this->metadata->closedByPeer;
    }

    public function getInfo(): WebsocketClientMetadata
    {
        return clone $this->metadata;
    }

    private function read(): void
    {
        $parser = new Parser($this->parser());

        try {
            while (($chunk = $this->socket->read()) !== null) {
                if ($chunk === '') {
                    continue;
                }

                $this->metadata->lastReadAt = \time();
                $this->metadata->bytesRead += \strlen($chunk);

                $this->heartbeatQueue?->update($this);
                $this->rateLimiter?->notifyBytesReceived($this, \strlen($chunk));

                $parser->push($chunk);

                $chunk = ''; // Free memory from last chunk read.
            }
        } catch (\Throwable $exception) {
            $message = 'TCP connection closed with exception: ' . $exception->getMessage();
        } finally {
            $this->heartbeatQueue?->remove($this);
            $parser->cancel();
        }

        /** @psalm-suppress PossiblyUndefinedVariable Seems to be a bug in Psalm causing this */
        $this->closeDeferred?->complete();
        $this->closeDeferred = null;

        if (!$this->metadata->closedAt) {
            $this->metadata->closedByPeer = true;
            $this->close(CloseCode::ABNORMAL_CLOSE, $message ?? 'TCP connection closed unexpectedly');
        }
    }

    private function onData(Opcode $opcode, string $data, bool $terminated): void
    {
        \assert(!$opcode->isControlFrame());

        $this->metadata->lastDataReadAt = \time();
        ++$this->metadata->framesRead;
        $this->rateLimiter?->notifyFramesReceived($this, 1);

        // Ignore further data received after initiating close.
        if ($this->metadata->closedAt) {
            return;
        }

        if (!$this->currentMessageEmitter) {
            if ($opcode === Opcode::Continuation) {
                $this->onError(
                    CloseCode::PROTOCOL_ERROR,
                    'Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY'
                );
                return;
            }

            ++$this->metadata->messagesRead;

            if (!$terminated) {
                $this->currentMessageEmitter = new Queue();
            }

            // Avoid holding a reference to the ReadableStream or Message object here so destructors will be invoked
            // if the message is not consumed by the user.
            $this->messageEmitter->push(self::createMessage(
                $opcode,
                $this->currentMessageEmitter
                    ? new ReadableIterableStream($this->currentMessageEmitter->pipe())
                    : $data,
            ));

            if (!$this->currentMessageEmitter) {
                return;
            }
        } elseif ($opcode !== Opcode::Continuation) {
            $this->onError(
                CloseCode::PROTOCOL_ERROR,
                'Illegal data type opcode after unfinished previous data type frame; opcode MUST be CONTINUATION'
            );
            return;
        }

        try {
            $this->currentMessageEmitter->push($data);
        } catch (DisposedException) {
            // Message disposed, ignore exception.
        }

        if ($terminated) {
            $this->currentMessageEmitter->complete();
            $this->currentMessageEmitter = null;
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

        ++$this->metadata->framesRead;
        $this->rateLimiter?->notifyFramesReceived($this, 1);

        // Close already completed, so ignore any further data from the parser.
        if ($this->metadata->closedAt && $this->closeDeferred === null) {
            return;
        }

        switch ($opcode) {
            case Opcode::Close:
                $this->closeDeferred?->complete();
                $this->closeDeferred = null;

                if ($this->metadata->closedAt) {
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
                    } elseif ($this->validateUtf8 && !\preg_match('//u', $reason)) {
                        $code = CloseCode::INCONSISTENT_FRAME_DATA_TYPE;
                        $reason = 'Close reason must be valid UTF-8';
                    }
                }

                $this->close($code, $reason);
                break;

            case Opcode::Ping:
                $this->write($data, Opcode::Pong);
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

    private function onError(int $code, string $reason): void
    {
        $this->close($code, $reason);
    }

    public function send(string $data): void
    {
        \assert((bool) \preg_match('//u', $data), 'Text data must be UTF-8');
        $this->pushData($data, Opcode::Text);
    }

    public function sendBinary(string $data): void
    {
        $this->pushData($data, Opcode::Binary);
    }

    public function stream(ReadableStream $stream): void
    {
        $this->pushStream($stream, Opcode::Text);
    }

    public function streamBinary(ReadableStream $stream): void
    {
        $this->pushStream($stream, Opcode::Binary);
    }

    public function ping(): void
    {
        ++$this->metadata->pingCount;
        $this->write((string) $this->metadata->pingCount, Opcode::Ping);
    }

    private function pushData(string $data, Opcode $opcode): void
    {
        if ($this->lastWrite || \strlen($data) > $this->frameSplitThreshold) {
            // Treat as a stream if another stream is pending or if splitting the data into multiple frames.
            $this->pushStream(new ReadableBuffer($data), $opcode);
            return;
        }

        // The majority of messages can be sent with a single frame.
        $this->sendData($data, $opcode);
    }

    private function sendData(string $data, Opcode $opcode): void
    {
        ++$this->metadata->messagesSent;
        $this->metadata->lastDataSentAt = \time();

        $rsv = 0;

        if ($this->compressionContext
            && $opcode === Opcode::Text
            && \strlen($data) > $this->compressionContext->getCompressionThreshold()
        ) {
            $rsv |= $this->compressionContext->getRsv();
            $data = $this->compressionContext->compress($data, true);
        }

        try {
            $this->write($data, $opcode, $rsv, true);
        } catch (\Throwable $exception) {
            $code = CloseCode::ABNORMAL_CLOSE;
            $reason = 'Writing to the client failed';
            $this->close($code, $reason);
            throw new ClosedException('Client unexpectedly closed', $code, $reason, $exception);
        }
    }

    private function pushStream(ReadableStream $stream, Opcode $opcode): void
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

        $rsv = 0;
        $compress = false;

        if ($this->compressionContext && $opcode === Opcode::Text) {
            $rsv |= $this->compressionContext->getRsv();
            $compress = true;
        }

        try {
            $chunk = $stream->read();

            if ($chunk === null) {
                $this->write('', $opcode, 0, true);
                return;
            }

            do {
                $buffer = $chunk;

                // Needed for Psalm.
                \assert(\is_string($buffer));

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

                        if ($compress) {
                            /** @psalm-suppress PossiblyNullReference */
                            $split = $this->compressionContext->compress($split, false);
                        }

                        $this->write($split, $opcode, $rsv, false);
                        $opcode = Opcode::Continuation;
                        $rsv = 0; // RSV must be 0 in continuation frames.
                    }

                    $buffer = \substr($buffer, $splitLength * $i, $splitLength);
                }

                if ($compress) {
                    /** @psalm-suppress PossiblyNullReference */
                    $buffer = $this->compressionContext->compress($buffer, $chunk === null);
                }

                $this->write($buffer, $opcode, $rsv, $chunk === null);
                $opcode = Opcode::Continuation;
                $rsv = 0; // RSV must be 0 in continuation frames.
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

    private function write(string $data, Opcode $opcode, int $rsv = 0, bool $isFinal = true): void
    {
        $frame = $this->compile($data, $opcode, $rsv, $isFinal);

        ++$this->metadata->framesSent;
        $this->metadata->bytesSent += \strlen($frame);
        $this->metadata->lastSentAt = \time();

        $this->socket->write($frame);
    }

    private function compile(string $data, Opcode $opcode, int $rsv, bool $isFinal): string
    {
        $length = \strlen($data);
        $w = \chr(((int) $isFinal << 7) | ($rsv << 4) | $opcode->value);

        $maskFlag = $this->masked ? 0x80 : 0;

        if ($length > 0xFFFF) {
            $w .= \chr(0x7F | $maskFlag) . \pack('J', $length);
        } elseif ($length > 0x7D) {
            $w .= \chr(0x7E | $maskFlag) . \pack('n', $length);
        } else {
            $w .= \chr($length | $maskFlag);
        }

        if ($this->masked) {
            $mask = \random_bytes(4);
            return $w . $mask . ($data ^ \str_repeat($mask, ($length + 3) >> 2));
        }

        return $w . $data;
    }

    public function isClosed(): bool
    {
        return (bool) $this->metadata->closedAt;
    }

    public function close(int $code = CloseCode::NORMAL_CLOSE, string $reason = ''): void
    {
        if ($this->metadata->closedAt) {
            return;
        }

        \assert($code !== CloseCode::NONE || $reason === '');

        $this->metadata->closedAt = \time();
        $this->metadata->closeCode = $code;
        $this->metadata->closeReason = $reason;

        $this->messageEmitter->complete();

        $this->currentMessageEmitter?->error(new ClosedException(
            'Connection closed while streaming message body',
            $code,
            $reason
        ));
        $this->currentMessageEmitter = null;

        if ($this->socket->isClosed()) {
            return;
        }

        try {
            $cancellation = new TimeoutCancellation($this->closePeriod);

            async(
                $this->write(...),
                $code !== CloseCode::NONE ? \pack('n', $code) . $reason : '',
                Opcode::Close
            )->await($cancellation);

            // Wait for peer close frame for configured number of seconds.
            $this->closeDeferred?->getFuture()->await($cancellation);
        } catch (\Throwable) {
            // Failed to write close frame or to receive response frame, but we were disconnecting anyway.
        }

        $this->socket->close();
        $this->lastWrite = null;
    }

    public function onClose(\Closure $onClose): void
    {
        $metadata = $this->metadata;
        $this->socket->onClose(static fn () => $onClose(clone $metadata));
    }

    /**
     * A stateful generator websocket frame parser.
     *
     * @return \Generator<int, int, string, void>
     */
    private function parser(): \Generator
    {
        $doUtf8Validation = $this->validateUtf8;
        $compressedFlag = $this->compressionContext?->getRsv() ?? 0;

        $dataMsgBytesRecd = 0;
        $savedBuffer = '';
        $compressed = false;

        while (true) {
            $payload = ''; // Free memory from last frame payload.

            $buffer = yield 2;

            $firstByte = \ord($buffer[0]);
            $secondByte = \ord($buffer[1]);

            $final = (bool) ($firstByte & 0b10000000);
            $rsv = ($firstByte & 0b01110000) >> 4;
            $opcode = $firstByte & 0b00001111;
            $isMasked = (bool) ($secondByte & 0b10000000);
            $maskingKey = '';
            $frameLength = $secondByte & 0b01111111;

            if ($opcode >= 3 && $opcode <= 7) {
                $this->onError(CloseCode::PROTOCOL_ERROR, 'Use of reserved non-control frame opcode');
                return;
            }

            if ($opcode >= 11 && $opcode <= 15) {
                $this->onError(CloseCode::PROTOCOL_ERROR, 'Use of reserved control frame opcode');
                return;
            }

            $opcode = Opcode::tryFrom($opcode);
            if (!$opcode) {
                $this->onError(CloseCode::PROTOCOL_ERROR, 'Invalid opcode');
                return;
            }

            $isControlFrame = $opcode->isControlFrame();

            if ($isControlFrame || $opcode === Opcode::Continuation) { // Control and continuation frames
                if ($rsv !== 0) {
                    $this->onError(CloseCode::PROTOCOL_ERROR, 'RSV must be 0 for control or continuation frames');
                    return;
                }
            } else { // Text and binary frames
                if ($rsv !== 0 && (!$this->compressionContext || $rsv & ~$compressedFlag)) {
                    $this->onError(CloseCode::PROTOCOL_ERROR, 'Invalid RSV value for negotiated extensions');
                    return;
                }

                $doUtf8Validation = $this->validateUtf8 && $opcode === Opcode::Text;
                $compressed = (bool) ($rsv & $compressedFlag);
            }

            if ($frameLength === 0x7E) {
                $frameLength = \unpack('n', yield 2)[1];
            } elseif ($frameLength === 0x7F) {
                [, $highBytes, $lowBytes] = \unpack('N2', yield 8);

                if (\PHP_INT_MAX === 0x7fffffff) {
                    if ($highBytes !== 0 || $lowBytes < 0) {
                        $this->onError(
                            CloseCode::MESSAGE_TOO_LARGE,
                            'Received payload exceeds maximum allowable size'
                        );
                        return;
                    }
                    $frameLength = $lowBytes;
                } else {
                    $frameLength = ($highBytes << 32) | $lowBytes;
                    if ($frameLength < 0) {
                        $this->onError(
                            CloseCode::PROTOCOL_ERROR,
                            'Most significant bit of 64-bit length field set'
                        );
                        return;
                    }
                }
            }

            if ($frameLength > 0 && $isMasked === $this->masked) {
                $this->onError(
                    CloseCode::PROTOCOL_ERROR,
                    'Payload mask error'
                );
                return;
            }

            if ($isControlFrame) {
                if (!$final) {
                    $this->onError(
                        CloseCode::PROTOCOL_ERROR,
                        'Illegal control frame fragmentation'
                    );
                    return;
                }

                if ($frameLength > 125) {
                    $this->onError(
                        CloseCode::PROTOCOL_ERROR,
                        'Control frame payload must be of maximum 125 bytes or less'
                    );
                    return;
                }
            }

            if ($this->frameSizeLimit && $frameLength > $this->frameSizeLimit) {
                $this->onError(
                    CloseCode::MESSAGE_TOO_LARGE,
                    'Received payload exceeds maximum allowable size'
                );
                return;
            }

            if ($this->messageSizeLimit && ($frameLength + $dataMsgBytesRecd) > $this->messageSizeLimit) {
                $this->onError(
                    CloseCode::MESSAGE_TOO_LARGE,
                    'Received payload exceeds maximum allowable size'
                );
                return;
            }

            if ($isMasked) {
                $maskingKey = yield 4;
            }

            if ($frameLength) {
                $payload = yield $frameLength;
            }

            if ($isMasked) {
                // This is memory hungry but it's ~70x faster than iterating byte-by-byte
                // over the masked string. Deal with it; manual iteration is untenable.
                /** @psalm-suppress InvalidOperand String operands expected. */
                $payload ^= \str_repeat($maskingKey, ($frameLength + 3) >> 2);
            }

            if ($isControlFrame) {
                $this->onControlFrame($opcode, $payload);
                continue;
            }

            if ($this->textOnly && $opcode === Opcode::Binary) {
                $this->onError(
                    CloseCode::UNACCEPTABLE_TYPE,
                    'BINARY opcodes (0x02) not accepted'
                );
                return;
            }

            $dataMsgBytesRecd += $frameLength;

            if ($savedBuffer !== '') {
                $payload = $savedBuffer . $payload;
                $savedBuffer = '';
            }

            if ($compressed) {
                /** @psalm-suppress PossiblyNullReference */
                $payload = $this->compressionContext->decompress($payload, $final);

                if ($payload === null) { // Decompression failed.
                    $this->onError(
                        CloseCode::PROTOCOL_ERROR,
                        'Invalid compressed data'
                    );
                    return;
                }
            }

            if ($doUtf8Validation) {
                if ($final) {
                    $valid = \preg_match('//u', $payload);
                } else {
                    for ($i = 0; !($valid = \preg_match('//u', $payload)); $i++) {
                        $savedBuffer = \substr($payload, -1) . $savedBuffer;
                        $payload = \substr($payload, 0, -1);

                        if ($i === 3) { // Remove a maximum of three bytes
                            break;
                        }
                    }
                }

                /** @psalm-suppress PossiblyUndefinedVariable Defined in either condition above. */
                if (!$valid) {
                    $this->onError(
                        CloseCode::INCONSISTENT_FRAME_DATA_TYPE,
                        'Invalid TEXT data; UTF-8 required'
                    );
                    return;
                }
            }

            if ($final) {
                $dataMsgBytesRecd = 0;
            }

            $this->onData($opcode, $payload, $final);
        }
    }
}
