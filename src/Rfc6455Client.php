<?php

namespace Amp\Websocket;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\DisposedException;
use Amp\Pipeline\Queue;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\TimeoutCancellation;
use Revolt\EventLoop;

final class Rfc6455Client implements Client
{
    private ?Future $lastWrite = null;

    /** @var Queue<Message> */
    private Queue $messageEmitter;

    /** @var ConcurrentIterator<Message> */
    private ConcurrentIterator $messageIterator;

    /** @var Queue<string>|null */
    private ?Queue $currentMessageEmitter = null;

    /** @var list<Closure(self, int, string):void>|null */
    private ?array $onClose = [];

    private ClientMetadata $metadata;

    private ?DeferredFuture $closeDeferred;

    /**
     * @param bool $masked True for client, false for server.
     */
    public function __construct(
        private Socket $socket,
        private Options $options,
        private bool $masked,
        private ?CompressionContext $compressionContext = null,
        private ?HeartbeatQueue $heartbeatQueue = null,
        private ?RateLimiter $rateLimiter = null,
    ) {
        $this->closeDeferred = new DeferredFuture;

        $this->messageEmitter = new Queue();
        $this->messageIterator = $this->messageEmitter->iterate();

        $this->metadata = new ClientMetadata(\time(), $this->compressionContext !== null);

        $this->heartbeatQueue?->insert($this);

        EventLoop::queue(fn () => $this->read());
    }

    public function receive(?Cancellation $cancellation = null): ?Message
    {
        try {
            if ($this->messageIterator->continue($cancellation)) {
                return $this->messageIterator->getValue();
            } else {
                return null;
            }
        } catch (DisposedException) {
            return null;
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

    public function isConnected(): bool
    {
        return !$this->metadata->closedAt;
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

    public function getOptions(): Options
    {
        return $this->options;
    }

    public function getInfo(): ClientMetadata
    {
        return clone $this->metadata;
    }

    private function read(): void
    {
        $parser = $this->parser();

        try {
            while (($chunk = $this->socket->read()) !== null) {
                if ($chunk === '') {
                    continue;
                }

                $this->metadata->lastReadAt = \time();
                $this->metadata->bytesRead += \strlen($chunk);

                $this->rateLimiter?->addToByteCount($this, \strlen($chunk));
                $this->heartbeatQueue?->update($this);

                $parser->send($chunk);

                $chunk = ''; // Free memory from last chunk read.

                $this->rateLimiter?->getSuspension($this)?->suspend();
            }
        } catch (\Throwable $exception) {
            $message = 'TCP connection closed with exception: ' . $exception->getMessage();
        }

        $this->closeDeferred?->complete();
        $this->closeDeferred = null;

        if (!$this->metadata->closedAt) {
            $this->metadata->closedByPeer = true;
            $this->close(Code::ABNORMAL_CLOSE, $message ?? 'TCP connection closed unexpectedly');
        }
    }

    private function onData(int $opcode, string $data, bool $terminated): void
    {
        // Ignore further data received after initiating close.
        if ($this->metadata->closedAt) {
            return;
        }

        $this->metadata->lastDataReadAt = \time();
        ++$this->metadata->framesRead;
        $this->rateLimiter?->addToFrameCount($this, 1);

        if (!$this->currentMessageEmitter) {
            if ($opcode === Opcode::CONT) {
                $this->onError(
                    Code::PROTOCOL_ERROR,
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
                    : new ReadableBuffer($data),
            ));

            if (!$this->currentMessageEmitter) {
                return;
            }
        } elseif ($opcode !== Opcode::CONT) {
            $this->onError(
                Code::PROTOCOL_ERROR,
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

    private static function createMessage(int $opcode, ReadableStream $stream): Message
    {
        if ($opcode === Opcode::BIN) {
            return Message::fromBinary($stream);
        }

        return Message::fromText($stream);
    }

    private function onControlFrame(int $opcode, string $data): void
    {
        // Close already completed, so ignore any further data from the parser.
        if ($this->metadata->closedAt && $this->closeDeferred === null) {
            return;
        }

        ++$this->metadata->framesRead;
        $this->rateLimiter?->addToFrameCount($this, 1);

        switch ($opcode) {
            case Opcode::CLOSE:
                $this->closeDeferred?->complete();
                $this->closeDeferred = null;

                if ($this->metadata->closedAt) {
                    break;
                }

                $this->metadata->closedByPeer = true;

                $length = \strlen($data);
                if ($length === 0) {
                    $code = Code::NONE;
                    $reason = '';
                } elseif ($length < 2) {
                    $code = Code::PROTOCOL_ERROR;
                    $reason = 'Close code must be two bytes';
                } else {
                    $code = \current(\unpack('n', \substr($data, 0, 2)));
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
                        $code = Code::PROTOCOL_ERROR;
                        $reason = 'Invalid close code';
                    } elseif ($this->options->isValidateUtf8() && !\preg_match('//u', $reason)) {
                        $code = Code::INCONSISTENT_FRAME_DATA_TYPE;
                        $reason = 'Close reason must be valid UTF-8';
                    }
                }

                $this->close($code, $reason);
                break;

            case Opcode::PING:
                $this->write($data, Opcode::PONG);
                break;

            case Opcode::PONG:
                if (!\preg_match('/^[1-9][0-9]*$/', $data)) {
                    // Ignore pong payload that is not an integer.
                    break;
                }

                // We need a min() here, else someone might just send a pong frame with a very high pong count and
                // leave TCP connection in open state... Then we'd accumulate connections which never are cleaned up...
                $this->metadata->pongCount = \min($this->metadata->pingCount, (int) $data);
                break;
        }
    }

    private function onError(int $code, string $reason): void
    {
        $this->close($code, $reason);
    }

    public function send(string $data): void
    {
        \assert((bool) \preg_match('//u', $data), 'Text data must be UTF-8');
        $this->pushData($data, Opcode::TEXT);
    }

    public function sendBinary(string $data): void
    {
        $this->pushData($data, Opcode::BIN);
    }

    public function stream(ReadableStream $stream): void
    {
        $this->pushStream($stream, Opcode::TEXT);
    }

    public function streamBinary(ReadableStream $stream): void
    {
        $this->pushStream($stream, Opcode::BIN);
    }

    public function ping(): void
    {
        $this->metadata->lastHeartbeatAt = \time();
        ++$this->metadata->pingCount;
        $this->write((string) $this->metadata->pingCount, Opcode::PING);
    }

    private function pushData(string $data, int $opcode): void
    {
        if ($this->lastWrite || \strlen($data) > $this->options->getFrameSplitThreshold()) {
            // Treat as a stream if another stream is pending or if splitting the data into multiple frames.
            $this->pushStream(new ReadableBuffer($data), $opcode);
            return;
        }

        // The majority of messages can be sent with a single frame.
        $this->sendData($data, $opcode);
    }

    private function sendData(string $data, int $opcode): void
    {
        ++$this->metadata->messagesSent;
        $this->metadata->lastDataSentAt = \time();

        $rsv = 0;

        if ($this->compressionContext
            && $opcode === Opcode::TEXT
            && \strlen($data) > $this->compressionContext->getCompressionThreshold()
        ) {
            $rsv |= $this->compressionContext->getRsv();
            $data = $this->compressionContext->compress($data, true);
        }

        try {
            $this->write($data, $opcode, $rsv, true);
        } catch (\Throwable $exception) {
            $code = Code::ABNORMAL_CLOSE;
            $reason = 'Writing to the client failed';
            $this->close($code, $reason);
            throw new ClosedException('Client unexpectedly closed', $code, $reason, $exception);
        }
    }

    private function pushStream(ReadableStream $stream, int $opcode): void
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

    private function sendStream(ReadableStream $stream, int $opcode): void
    {
        ++$this->metadata->messagesSent;
        $this->metadata->lastDataSentAt = \time();

        $rsv = 0;
        $compress = false;

        if ($this->compressionContext && $opcode === Opcode::TEXT) {
            $rsv |= $this->compressionContext->getRsv();
            $compress = true;
        }

        try {
            $chunk = $stream->read();

            if ($chunk === null) {
                $this->write('', $opcode, 0, true);
                return;
            }

            $chunks = [$chunk];
            $bufferedLength = \strlen($chunk);
            $streamThreshold = $this->options->getStreamThreshold();
            $splitThreshold = $this->options->getFrameSplitThreshold();

            do {
                do {
                    // Perform another read to avoid sending an empty frame on stream end.
                    $chunk = $stream->read();

                    if ($chunk === null || $bufferedLength >= $streamThreshold) {
                        break;
                    }

                    $chunks[] = $chunk;
                    $bufferedLength += \strlen($chunk);
                } while (true);

                $buffer = \count($chunks) === 1 ? $chunks[0] : \implode($chunks);
                $chunks = [$chunk];

                if ($bufferedLength > $splitThreshold) {
                    $splitLength = $bufferedLength;
                    $slices = (int) \ceil($splitLength / $splitThreshold);
                    $splitLength = (int) \ceil($splitLength / $slices);

                    for ($i = 0; $i < $slices - 1; ++$i) {
                        $split = \substr($buffer, $splitLength * $i, $splitLength);

                        if ($compress) {
                            /** @psalm-suppress PossiblyNullReference */
                            $split = $this->compressionContext->compress($split, false);
                        }

                        $this->write($split, $opcode, $rsv, false);
                        $opcode = Opcode::CONT;
                        $rsv = 0; // RSV must be 0 in continuation frames.
                    }

                    $buffer = \substr($buffer, $splitLength * $i, $splitLength);
                }

                if ($compress) {
                    /** @psalm-suppress PossiblyNullReference */
                    $buffer = $this->compressionContext->compress($buffer, $chunk === null);
                }

                $this->write($buffer, $opcode, $rsv, $chunk === null);
                $opcode = Opcode::CONT;
                $rsv = 0; // RSV must be 0 in continuation frames.
                $bufferedLength = 0;
            } while ($chunk !== null);
        } catch (StreamException $exception) {
            $code = Code::ABNORMAL_CLOSE;
            $reason = 'Writing to the client failed';
            $this->close($code, $reason);
            throw new ClosedException('Client unexpectedly closed', $code, $reason, $exception);
        } catch (\Throwable $exception) {
            $this->close(Code::UNEXPECTED_SERVER_ERROR, 'Error while reading message data');
            throw $exception;
        }
    }

    private function write(string $data, int $opcode, int $rsv = 0, bool $isFinal = true): void
    {
        $frame = $this->compile($data, $opcode, $rsv, $isFinal);

        ++$this->metadata->framesSent;
        $this->metadata->bytesSent += \strlen($frame);
        $this->metadata->lastSentAt = \time();

        $this->socket->write($frame);
    }

    private function compile(string $data, int $opcode, int $rsv, bool $isFinal): string
    {
        $length = \strlen($data);
        $w = \chr(((int) $isFinal << 7) | ($rsv << 4) | $opcode);

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

    public function close(int $code = Code::NORMAL_CLOSE, string $reason = ''): array
    {
        if ($this->metadata->closedAt) {
            return [$this->metadata->closeCode, $this->metadata->closeReason];
        }

        \assert($code !== Code::NONE || $reason === '');

        $this->metadata->closedAt = \time();
        $this->metadata->closeCode = $code;
        $this->metadata->closeReason = $reason;

        try {
            $this->lastWrite?->await();
        } catch (\Throwable) {
            return [$this->metadata->closeCode, $this->metadata->closeReason];
        }

        $this->write($code !== Code::NONE ? \pack('n', $code) . $reason : '', Opcode::CLOSE);

        if ($this->currentMessageEmitter) {
            $emitter = $this->currentMessageEmitter;
            $this->currentMessageEmitter = null;
            $emitter->error(new ClosedException(
                'Connection closed while streaming message body',
                $code,
                $reason
            ));
        }

        match ($code) {
            Code::NORMAL_CLOSE, Code::GOING_AWAY, Code::NONE, => $this->messageEmitter->complete(),
            default => $this->messageEmitter->error(new ClosedException(
                'Connection closed abnormally while awaiting message',
                $code,
                $reason
            )),
        };

        try {
            // Wait for peer close frame for configured number of seconds.
            $this->closeDeferred?->getFuture()
                ->await(new TimeoutCancellation($this->options->getClosePeriod()));
        } catch (\Throwable $exception) {
            // Failed to write close frame or to receive response frame, but we were disconnecting anyway.
        }

        $this->socket->close();
        $this->lastWrite = Future::complete();

        $onClose = $this->onClose;
        $this->onClose = null;

        if ($onClose !== null) {
            foreach ($onClose as $callback) {
                EventLoop::queue(fn () => $callback($this, $code, $reason));
            }
        }

        $this->heartbeatQueue?->remove($this);

        return [$this->metadata->closeCode, $this->metadata->closeReason];
    }

    public function onClose(\Closure $closure): void
    {
        if ($this->onClose === null) {
            EventLoop::queue(fn () => $closure($this, $this->metadata->closeCode, $this->metadata->closeReason));
            return;
        }

        $this->onClose[] = $closure;
    }

    /**
     * A stateful generator websocket frame parser.
     *
     * @return \Generator
     */
    private function parser(): \Generator
    {
        $frameSizeLimit = $this->options->getFrameSizeLimit();
        $messageSizeLimit = $this->options->getMessageSizeLimit();
        $textOnly = $this->options->isTextOnly();
        $doUtf8Validation = $validateUtf8 = $this->options->isValidateUtf8();

        $compressionContext = $this->compressionContext;
        $compressedFlag = $compressionContext ? $compressionContext->getRsv() : 0;

        $dataMsgBytesRecd = 0;
        $savedBuffer = '';
        $compressed = false;

        $buffer = yield;
        $offset = 0;
        $bufferSize = \strlen($buffer);

        while (true) {
            $payload = ''; // Free memory from last frame payload.

            while ($bufferSize < 2) {
                $buffer = \substr($buffer, $offset);
                $offset = 0;
                $buffer .= yield;
                $bufferSize = \strlen($buffer);
            }

            $firstByte = \ord($buffer[$offset]);
            $secondByte = \ord($buffer[$offset + 1]);

            $offset += 2;
            $bufferSize -= 2;

            $final = (bool) ($firstByte & 0b10000000);
            $rsv = ($firstByte & 0b01110000) >> 4;
            $opcode = $firstByte & 0b00001111;
            $isMasked = (bool) ($secondByte & 0b10000000);
            $maskingKey = '';
            $frameLength = $secondByte & 0b01111111;

            if ($opcode >= 3 && $opcode <= 7) {
                $this->onError(Code::PROTOCOL_ERROR, 'Use of reserved non-control frame opcode');
                return;
            }

            if ($opcode >= 11 && $opcode <= 15) {
                $this->onError(Code::PROTOCOL_ERROR, 'Use of reserved control frame opcode');
                return;
            }

            $isControlFrame = $opcode >= 0x08;

            if ($isControlFrame || $opcode === Opcode::CONT) { // Control and continuation frames
                if ($rsv !== 0) {
                    $this->onError(Code::PROTOCOL_ERROR, 'RSV must be 0 for control or continuation frames');
                    return;
                }
            } else { // Text and binary frames
                if ($rsv !== 0 && (!$compressionContext || $rsv & ~$compressedFlag)) {
                    $this->onError(Code::PROTOCOL_ERROR, 'Invalid RSV value for negotiated extensions');
                    return;
                }

                $doUtf8Validation = $validateUtf8 && $opcode === Opcode::TEXT;
                $compressed = (bool) ($rsv & $compressedFlag);
            }

            if ($frameLength === 0x7E) {
                while ($bufferSize < 2) {
                    $buffer = \substr($buffer, $offset);
                    $offset = 0;
                    $buffer .= yield;
                    $bufferSize = \strlen($buffer);
                }

                $frameLength = \unpack('n', $buffer[$offset] . $buffer[$offset + 1])[1];
                $offset += 2;
                $bufferSize -= 2;
            } elseif ($frameLength === 0x7F) {
                while ($bufferSize < 8) {
                    $buffer = \substr($buffer, $offset);
                    $offset = 0;
                    $buffer .= yield;
                    $bufferSize = \strlen($buffer);
                }

                $lengthLong32Pair = \unpack('N2', \substr($buffer, $offset, 8));
                $offset += 8;
                $bufferSize -= 8;

                if (\PHP_INT_MAX === 0x7fffffff) {
                    if ($lengthLong32Pair[1] !== 0 || $lengthLong32Pair[2] < 0) {
                        $this->onError(
                            Code::MESSAGE_TOO_LARGE,
                            'Received payload exceeds maximum allowable size'
                        );
                        return;
                    }
                    $frameLength = $lengthLong32Pair[2];
                } else {
                    $frameLength = ($lengthLong32Pair[1] << 32) | $lengthLong32Pair[2];
                    if ($frameLength < 0) {
                        $this->onError(
                            Code::PROTOCOL_ERROR,
                            'Most significant bit of 64-bit length field set'
                        );
                        return;
                    }
                }
            }

            if ($frameLength > 0 && $isMasked === $this->masked) {
                $this->onError(
                    Code::PROTOCOL_ERROR,
                    'Payload mask error'
                );
                return;
            }

            if ($isControlFrame) {
                if (!$final) {
                    $this->onError(
                        Code::PROTOCOL_ERROR,
                        'Illegal control frame fragmentation'
                    );
                    return;
                }

                if ($frameLength > 125) {
                    $this->onError(
                        Code::PROTOCOL_ERROR,
                        'Control frame payload must be of maximum 125 bytes or less'
                    );
                    return;
                }
            }

            if ($frameSizeLimit && $frameLength > $frameSizeLimit) {
                $this->onError(
                    Code::MESSAGE_TOO_LARGE,
                    'Received payload exceeds maximum allowable size'
                );
                return;
            }

            if ($messageSizeLimit && ($frameLength + $dataMsgBytesRecd) > $messageSizeLimit) {
                $this->onError(
                    Code::MESSAGE_TOO_LARGE,
                    'Received payload exceeds maximum allowable size'
                );
                return;
            }

            if ($textOnly && $opcode === Opcode::BIN) {
                $this->onError(
                    Code::UNACCEPTABLE_TYPE,
                    'BINARY opcodes (0x02) not accepted'
                );
                return;
            }

            if ($isMasked) {
                while ($bufferSize < 4) {
                    $buffer = \substr($buffer, $offset);
                    $offset = 0;
                    $buffer .= yield;
                    $bufferSize = \strlen($buffer);
                }

                $maskingKey = \substr($buffer, $offset, 4);
                $offset += 4;
                $bufferSize -= 4;
            }

            while ($bufferSize < $frameLength) {
                $chunk = yield;
                $buffer .= $chunk;
                $bufferSize += \strlen($chunk);
            }

            $payload = \substr($buffer, $offset, $frameLength);
            $buffer = \substr($buffer, $offset + $frameLength);
            $offset = 0;
            $bufferSize = \strlen($buffer);

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

            $dataMsgBytesRecd += $frameLength;

            if ($savedBuffer !== '') {
                $payload = $savedBuffer . $payload;
                $savedBuffer = '';
            }

            if ($compressed) {
                /** @psalm-suppress PossiblyNullReference */
                $payload = $compressionContext->decompress($payload, $final);

                if ($payload === null) { // Decompression failed.
                    $this->onError(
                        Code::PROTOCOL_ERROR,
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
                        Code::INCONSISTENT_FRAME_DATA_TYPE,
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
