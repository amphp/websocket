<?php

namespace Amp\Websocket;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\InputStream;
use Amp\ByteStream\IteratorStream;
use Amp\ByteStream\StreamException;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Emitter;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\Success;
use cash\LRUCache;
use function Amp\call;
use function Amp\getCurrentTime;

final class Rfc6455Client implements Client
{
    /** @var self[] */
    private static $clients = [];

    /** @var int[] */
    private static $bytesReadInLastSecond = [];

    /** @var int[] */
    private static $framesReadInLastSecond = [];

    /** @var Deferred[] */
    private static $rateDeferreds = [];

    /** @var string|null */
    private static $watcher;

    /** @var LRUCache&\IteratorAggregate Least-recently-used cache of next ping (heartbeat) times. */
    private static $heartbeatTimeouts;

    /** @var int Cached current time. */
    private static $now;

    /** @var Options */
    private $options;

    /** @var Socket */
    private $socket;

    /** @var Promise|null */
    private $lastWrite;

    /** @var Promise|null */
    private $lastEmit;

    /** @var bool */
    private $masked;

    /** @var CompressionContext|null */
    private $compressionContext;

    /** @var Emitter|null */
    private $currentMessageEmitter;

    /** @var Deferred|null */
    private $nextMessageDeferred;

    /** @var Message[] */
    private $messages = [];

    /** @var callable[]|null */
    private $onClose = [];

    /** @var ClientMetadata */
    private $metadata;

    /** @var Deferred|null */
    private $closeDeferred;

    /**
     * @param Socket                  $socket
     * @param Options                 $options
     * @param bool                    $masked True for client, false for server.
     * @param CompressionContext|null $compression
     */
    public function __construct(
        Socket $socket,
        Options $options,
        bool $masked,
        ?CompressionContext $compression = null
    ) {
        $this->socket = $socket;
        $this->options = $options;
        $this->masked = $masked;
        $this->compressionContext = $compression;
        $this->closeDeferred = new Deferred;

        if (self::$watcher === null) {
            self::$now = getCurrentTime();
            self::$heartbeatTimeouts = new class(\PHP_INT_MAX) extends LRUCache implements \IteratorAggregate {
                public function getIterator(): \Iterator
                {
                    yield from $this->data;
                }
            };

            self::$watcher = Loop::repeat(1000, static function (): void {
                self::$now = getCurrentTime();

                self::$bytesReadInLastSecond = [];
                self::$framesReadInLastSecond = [];

                if (!empty(self::$rateDeferreds)) {
                    /** @psalm-suppress PossiblyNullArgument */
                    Loop::unreference(self::$watcher);

                    $rateDeferreds = self::$rateDeferreds;
                    self::$rateDeferreds = [];

                    foreach ($rateDeferreds as $deferred) {
                        $deferred->resolve();
                    }
                }

                foreach (self::$heartbeatTimeouts as $clientId => $expiryTime) {
                    if ($expiryTime >= self::$now) {
                        break;
                    }

                    $client = self::$clients[$clientId];
                    self::$heartbeatTimeouts->put($clientId, self::$now + $client->options->getHeartbeatPeriod() * 1000);

                    if ($client->getUnansweredPingCount() > $client->options->getQueuedPingLimit()) {
                        $client->close(Code::POLICY_VIOLATION, 'Exceeded unanswered PING limit');
                        continue;
                    }

                    $client->ping();
                }
            });
            Loop::unreference(self::$watcher);
        }

        $this->metadata = new ClientMetadata(self::$now, $compression !== null);
        self::$clients[$this->metadata->id] = $this;

        if ($this->options->isHeartbeatEnabled()) {
            self::$heartbeatTimeouts->put($this->metadata->id, self::$now + $this->options->getHeartbeatPeriod() * 1000);
        }

        Promise\rethrow(new Coroutine($this->read()));
    }

    public function receive(): Promise
    {
        if ($this->nextMessageDeferred) {
            throw new \Error('Await the previous promise returned from receive() before calling receive() again.');
        }

        // There might be messages already buffered and a close frame already received
        if ($this->messages) {
            $message = \reset($this->messages);
            unset($this->messages[\key($this->messages)]);

            return new Success($message);
        }

        if ($this->metadata->closedAt) {
            return new Success;
        }

        $this->nextMessageDeferred = new Deferred;

        return $this->nextMessageDeferred->promise();
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

    private function read(): \Generator
    {
        $maxFramesPerSecond = $this->options->getFramesPerSecondLimit();
        $maxBytesPerSecond = $this->options->getBytesPerSecondLimit();
        $heartbeatEnabled = $this->options->isHeartbeatEnabled();
        $heartbeatPeriod = $this->options->getHeartbeatPeriod() * 1000;

        $parser = $this->parser();

        try {
            while (($chunk = yield $this->socket->read()) !== null) {
                if ($chunk === '') {
                    continue;
                }

                $this->metadata->lastReadAt = self::$now;
                $this->metadata->bytesRead += \strlen($chunk);
                self::$bytesReadInLastSecond[$this->metadata->id] = (self::$bytesReadInLastSecond[$this->metadata->id] ?? 0) + \strlen($chunk);

                if ($heartbeatEnabled) {
                    self::$heartbeatTimeouts->put($this->metadata->id, self::$now + $heartbeatPeriod);
                }

                $parser->send($chunk);

                $chunk = ''; // Free memory from last chunk read.

                if ((self::$framesReadInLastSecond[$this->metadata->id] ?? 0) >= $maxFramesPerSecond
                    || (self::$bytesReadInLastSecond[$this->metadata->id] ?? 0) >= $maxBytesPerSecond
                ) {
                    /** @psalm-suppress PossiblyNullArgument */
                    Loop::reference(self::$watcher); // Reference watcher to keep loop running until rate limit released.
                    self::$rateDeferreds[$this->metadata->id] = $deferred = new Deferred;
                    yield $deferred->promise();
                }

                if ($this->lastEmit && !$this->metadata->closedAt) {
                    yield $this->lastEmit;
                }
            }
        } catch (\Throwable $exception) {
            $message = 'TCP connection closed with exception: ' . $exception->getMessage();
        }

        if ($this->closeDeferred !== null) {
            $deferred = $this->closeDeferred;
            $this->closeDeferred = null;
            $deferred->resolve();
        }

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

        $this->metadata->lastDataReadAt = self::$now;
        ++$this->metadata->framesRead;
        self::$framesReadInLastSecond[$this->metadata->id] = (self::$framesReadInLastSecond[$this->metadata->id] ?? 0) + 1;

        if (!$this->currentMessageEmitter) {
            if ($opcode === Opcode::CONT) {
                $this->onError(
                    Code::PROTOCOL_ERROR,
                    'Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY'
                );
                return;
            }

            if ($terminated) {
                $stream = new InMemoryStream($data);
                ++$this->metadata->messagesRead;
            } else {
                $this->currentMessageEmitter = new Emitter;
                $stream = new IteratorStream($this->currentMessageEmitter->iterate());
            }

            if ($opcode === Opcode::BIN) {
                $message = Message::fromBinary($stream);
            } else {
                $message = Message::fromText($stream);
            }

            if ($this->nextMessageDeferred) {
                $deferred = $this->nextMessageDeferred;
                $this->nextMessageDeferred = null;
                $deferred->resolve($message);
            } else {
                $this->messages[] = $message;
            }

            if ($terminated) {
                return;
            }
        } elseif ($opcode !== Opcode::CONT) {
            $this->onError(
                Code::PROTOCOL_ERROR,
                'Illegal data type opcode after unfinished previous data type frame; opcode MUST be CONTINUATION'
            );
            return;
        }

        $promise = $this->currentMessageEmitter->emit($data);
        $this->lastEmit = $this->nextMessageDeferred ? null : $promise;

        if ($terminated) {
            $emitter = $this->currentMessageEmitter;
            $this->currentMessageEmitter = null;
            $emitter->complete();

            ++$this->metadata->messagesRead;
        }
    }

    private function onControlFrame(int $opcode, string $data): void
    {
        // Close already completed, so ignore any further data from the parser.
        if ($this->metadata->closedAt && $this->closeDeferred === null) {
            return;
        }

        ++$this->metadata->framesRead;
        self::$framesReadInLastSecond[$this->metadata->id] = (self::$framesReadInLastSecond[$this->metadata->id] ?? 0) + 1;

        switch ($opcode) {
            case Opcode::CLOSE:
                if ($this->closeDeferred) {
                    $deferred = $this->closeDeferred;
                    $this->closeDeferred = null;
                    $deferred->resolve();
                }

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

    public function send(string $data): Promise
    {
        \assert((bool) \preg_match('//u', $data), 'Text data must be UTF-8');
        return $this->lastWrite = new Coroutine($this->sendData($data, Opcode::TEXT));
    }

    public function sendBinary(string $data): Promise
    {
        return $this->lastWrite = new Coroutine($this->sendData($data, Opcode::BIN));
    }

    public function stream(InputStream $stream): Promise
    {
        return $this->lastWrite = new Coroutine($this->sendStream($stream, Opcode::TEXT));
    }

    public function streamBinary(InputStream $stream): Promise
    {
        return $this->lastWrite = new Coroutine($this->sendStream($stream, Opcode::BIN));
    }

    public function ping(): Promise
    {
        $this->metadata->lastHeartbeatAt = self::$now;
        ++$this->metadata->pingCount;
        return $this->write((string) $this->metadata->pingCount, Opcode::PING);
    }

    private function sendData(string $data, int $opcode): \Generator
    {
        if ($this->lastWrite) {
            yield $this->lastWrite;
        }

        ++$this->metadata->messagesSent;
        $this->metadata->lastDataSentAt = self::$now;

        $rsv = 0;
        $compress = false;

        if ($this->compressionContext
            && $opcode === Opcode::TEXT
            && \strlen($data) > $this->compressionContext->getCompressionThreshold()
        ) {
            $rsv |= $this->compressionContext->getRsv();
            $compress = true;
        }

        try {
            if (\strlen($data) > $this->options->getFrameSplitThreshold()) {
                $length = \strlen($data);
                $slices = (int) \ceil($length / $this->options->getFrameSplitThreshold());
                $length = (int) \ceil($length / $slices);

                for ($i = 0; $i < $slices - 1; ++$i) {
                    $chunk = \substr($data, $length * $i, $length);

                    if ($compress) {
                        /** @psalm-suppress PossiblyNullReference */
                        $chunk = $this->compressionContext->compress($chunk, false);
                    }

                    yield $this->write($chunk, $opcode, $rsv, false);
                    $opcode = Opcode::CONT;
                    $rsv = 0; // RSV must be 0 in continuation frames.
                }

                $data = \substr($data, $length * $i, $length);
            }

            if ($compress) {
                /** @psalm-suppress PossiblyNullReference */
                $data = $this->compressionContext->compress($data, true);
            }

            yield $this->write($data, $opcode, $rsv, true);
        } catch (StreamException $exception) {
            $code = Code::ABNORMAL_CLOSE;
            $reason = 'Writing to the client failed';
            yield $this->close($code, $reason);
            throw new ClosedException('Client unexpectedly closed', $code, $reason);
        }
    }

    private function sendStream(InputStream $stream, int $opcode): \Generator
    {
        if ($this->lastWrite) {
            yield $this->lastWrite;
        }

        $rsv = 0;
        $compress = false;

        if ($this->compressionContext && $opcode === Opcode::TEXT) {
            $rsv |= $this->compressionContext->getRsv();
            $compress = true;
        }

        try {
            $buffer = yield $stream->read();

            if ($buffer === null) {
                return yield $this->write('', $opcode, 0, true);
            }

            $streamThreshold = $this->options->getStreamThreshold();

            while (($chunk = yield $stream->read()) !== null) {
                if ($chunk === '') {
                    continue;
                }

                if (\strlen($buffer) < $streamThreshold) {
                    $buffer .= $chunk;
                    continue;
                }

                if ($compress) {
                    /** @psalm-suppress PossiblyNullReference */
                    $buffer = $this->compressionContext->compress($buffer, false);
                }

                yield $this->write($buffer, $opcode, $rsv, false);
                $opcode = Opcode::CONT;
                $rsv = 0; // RSV must be 0 in continuation frames.

                $buffer = $chunk;
            }

            if ($compress) {
                /** @psalm-suppress PossiblyNullReference */
                $buffer = $this->compressionContext->compress($buffer, true);
            }

            yield $this->write($buffer, $opcode, $rsv, true);
        } catch (StreamException $exception) {
            $code = Code::ABNORMAL_CLOSE;
            $reason = 'Writing to the client failed';
            yield $this->close($code, $reason);
            throw new ClosedException('Client unexpectedly closed', $code, $reason);
        } catch (\Throwable $exception) {
            yield $this->close(Code::UNEXPECTED_SERVER_ERROR, 'Error while reading message data');
            throw $exception;
        }
    }

    private function write(string $data, int $opcode, int $rsv = 0, bool $isFinal = true): Promise
    {
        if ($this->metadata->closedAt) {
            return new Success(0);
        }

        $frame = $this->compile($data, $opcode, $rsv, $isFinal);

        ++$this->metadata->framesSent;
        $this->metadata->bytesSent += \strlen($frame);
        $this->metadata->lastSentAt = self::$now;

        return $this->socket->write($frame);
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

    public function close(int $code = Code::NORMAL_CLOSE, string $reason = ''): Promise
    {
        if ($this->metadata->closedAt) {
            return new Success([$this->metadata->closeCode, $this->metadata->closeReason]);
        }

        return call(function () use ($code, $reason) {
            try {
                \assert($code !== Code::NONE || $reason === '');
                $promise = $this->write($code !== Code::NONE ? \pack('n', $code) . $reason : '', Opcode::CLOSE);

                $this->metadata->closedAt = self::$now;
                $this->metadata->closeCode = $code;
                $this->metadata->closeReason = $reason;

                if ($this->currentMessageEmitter) {
                    $emitter = $this->currentMessageEmitter;
                    $this->currentMessageEmitter = null;
                    $emitter->fail(new ClosedException(
                        'Connection closed while streaming message body',
                        $code,
                        $reason
                    ));
                }

                if ($this->nextMessageDeferred) {
                    $deferred = $this->nextMessageDeferred;
                    $this->nextMessageDeferred = null;

                    switch ($code) {
                        case Code::NORMAL_CLOSE:
                        case Code::GOING_AWAY:
                        case Code::NONE:
                            $deferred->resolve();
                            break;

                        default:
                            $deferred->fail(new ClosedException(
                                'Connection closed abnormally while awaiting message',
                                $code,
                                $reason
                            ));
                            break;
                    }
                }

                yield $promise; // Wait for writing close frame to complete.

                if ($this->closeDeferred !== null) {
                    // Wait for peer close frame for configured number of seconds.
                    yield Promise\timeout($this->closeDeferred->promise(), $this->options->getClosePeriod() * 1000);
                }
            } catch (\Throwable $exception) {
                // Failed to write close frame or to receive response frame, but we were disconnecting anyway.
            }

            $this->socket->close();
            $this->lastWrite = null;
            $this->lastEmit = null;

            $onClose = $this->onClose;
            $this->onClose = null;

            if ($onClose !== null) {
                foreach ($onClose as $callback) {
                    Promise\rethrow(call($callback, $this, $code, $reason));
                }
            }

            unset(self::$clients[$this->metadata->id]);
            self::$heartbeatTimeouts->remove($this->metadata->id);

            if (empty(self::$clients)) {
                /** @psalm-suppress PossiblyNullArgument */
                Loop::cancel(self::$watcher);
                self::$watcher = null;
                self::$heartbeatTimeouts = null;
            }

            return [$this->metadata->closeCode, $this->metadata->closeReason];
        });
    }

    public function onClose(callable $callback): void
    {
        if ($this->onClose === null) {
            Promise\rethrow(call($callback, $this, $this->metadata->closeCode, $this->metadata->closeReason));
            return;
        }

        $this->onClose[] = $callback;
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
