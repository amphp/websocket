<?php

namespace Amp\Websocket;

use Amp\ByteStream\InMemoryStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\PipelineStream;
use Amp\ByteStream\StreamException;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Pipeline\Emitter;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\TimeoutCancellationToken;
use cash\LRUCache;
use Revolt\EventLoop;

final class Rfc6455Client implements Client
{
    /** @var self[] */
    private static array $clients = [];

    /** @var int[] */
    private static array $bytesReadInLastSecond = [];

    /** @var int[] */
    private static array $framesReadInLastSecond = [];

    /** @var DeferredFuture[] */
    private static array $rateDeferreds = [];

    private static string $watcher;

    /** @var LRUCache&\IteratorAggregate Least-recently-used cache of next ping (heartbeat) times. */
    private static LRUCache $heartbeatTimeouts;

    /** @var int Cached current time. */
    private static int $now;

    private Options $options;

    private Socket $socket;

    private ?Future $lastWrite = null;

    private ?Future $lastEmit = null;

    private bool $masked;

    private ?CompressionContext $compressionContext;

    private ?Emitter $currentMessageEmitter = null;

    private ?DeferredFuture $nextMessageDeferred = null;

    /** @var Message[] */
    private array $messages = [];

    /** @var callable[]|null */
    private ?array $onClose = [];

    private ClientMetadata $metadata;

    private ?DeferredFuture $closeDeferred;

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
        $this->closeDeferred = new DeferredFuture;

        if (empty(self::$clients)) {
            self::$now = \time();
            self::$heartbeatTimeouts = new class(\PHP_INT_MAX) extends LRUCache implements \IteratorAggregate {
                public function getIterator(): \Iterator
                {
                    yield from $this->data;
                }
            };

            self::$watcher = EventLoop::repeat(1, static function (): void {
                self::$now = \time();

                self::$bytesReadInLastSecond = [];
                self::$framesReadInLastSecond = [];

                if (!empty(self::$rateDeferreds)) {
                    EventLoop::unreference(self::$watcher);

                    $rateDeferreds = self::$rateDeferreds;
                    self::$rateDeferreds = [];

                    foreach ($rateDeferreds as $deferred) {
                        $deferred->complete(null);
                    }
                }

                foreach (self::$heartbeatTimeouts as $clientId => $expiryTime) {
                    if ($expiryTime >= self::$now) {
                        break;
                    }

                    $client = self::$clients[$clientId];
                    self::$heartbeatTimeouts->put($clientId, self::$now + $client->options->getHeartbeatPeriod());

                    if ($client->getUnansweredPingCount() > $client->options->getQueuedPingLimit()) {
                        $client->close(Code::POLICY_VIOLATION, 'Exceeded unanswered PING limit');
                        continue;
                    }

                    $client->ping();
                }
            });
            EventLoop::unreference(self::$watcher);
        }

        $this->metadata = new ClientMetadata(self::$now, $compression !== null);
        self::$clients[$this->metadata->id] = $this;

        if ($this->options->isHeartbeatEnabled()) {
            self::$heartbeatTimeouts->put($this->metadata->id, self::$now + $this->options->getHeartbeatPeriod());
        }

        EventLoop::queue(fn() => $this->read());
    }

    public function receive(): ?Message
    {
        if ($this->nextMessageDeferred) {
            throw new \Error('Await the previous promise returned from receive() before calling receive() again.');
        }

        // There might be messages already buffered and a close frame already received
        if ($this->messages) {
            $message = \reset($this->messages);
            unset($this->messages[\key($this->messages)]);

            return $message;
        }

        if ($this->metadata->closedAt) {
            return null;
        }

        $this->nextMessageDeferred = new DeferredFuture;

        return $this->nextMessageDeferred->getFuture()->await();
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
        $maxFramesPerSecond = $this->options->getFramesPerSecondLimit();
        $maxBytesPerSecond = $this->options->getBytesPerSecondLimit();
        $heartbeatEnabled = $this->options->isHeartbeatEnabled();
        $heartbeatPeriod = $this->options->getHeartbeatPeriod();

        $parser = $this->parser();

        try {
            while (($chunk = $this->socket->read()) !== null) {
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
                    || (self::$bytesReadInLastSecond[$this->metadata->id] ?? 0) >= $maxBytesPerSecond) {
                    EventLoop::reference(self::$watcher); // Reference watcher to keep loop running until rate limit released.
                    self::$rateDeferreds[$this->metadata->id] = $deferred = new DeferredFuture;
                    $deferred->getFuture()->await();
                }

                if (!$this->metadata->closedAt) {
                    try {
                        $this->lastEmit?->await();
                    } catch (\Throwable) {
                        // Ignore if the message was disposed.
                    }
                }
            }
        } catch (\Throwable $exception) {
            $message = 'TCP connection closed with exception: ' . $exception->getMessage();
        }

        $this->closeDeferred?->complete(null);
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
                $stream = new PipelineStream($this->currentMessageEmitter->asPipeline());
            }

            if ($opcode === Opcode::BIN) {
                $message = Message::fromBinary($stream);
            } else {
                $message = Message::fromText($stream);
            }

            if ($this->nextMessageDeferred) {
                $deferred = $this->nextMessageDeferred;
                $this->nextMessageDeferred = null;
                $deferred->complete($message);
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

        $future = $this->currentMessageEmitter->emit($data);
        $this->lastEmit = $this->nextMessageDeferred ? null : $future;

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
                $this->closeDeferred?->complete(null);
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
                $this->write($data, Opcode::PONG)->ignore();
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

    public function send(string $data): Future
    {
        \assert((bool) \preg_match('//u', $data), 'Text data must be UTF-8');
        return $this->pushData($data, Opcode::TEXT);
    }

    public function sendBinary(string $data): Future
    {
        return $this->pushData($data, Opcode::BIN);
    }

    public function stream(ReadableStream $stream): Future
    {
        return $this->pushStream($stream, Opcode::TEXT);
    }

    public function streamBinary(ReadableStream $stream): Future
    {
        return $this->pushStream($stream, Opcode::BIN);
    }

    public function ping(): void
    {
        $this->metadata->lastHeartbeatAt = self::$now;
        ++$this->metadata->pingCount;
        $this->write((string) $this->metadata->pingCount, Opcode::PING)->ignore();
    }

    private function pushData(string $data, int $opcode): Future
    {
        if ($this->lastWrite) {
            // Use a coroutine to send data if an outgoing stream is still pending.
            return $this->lastWrite->map(fn () => $this->sendData($data, $opcode)->await());
        }

        return $this->sendData($data, $opcode);
    }

    private function sendData(string $data, int $opcode): Future
    {
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

                $this->write($chunk, $opcode, $rsv, false)->ignore();
                $opcode = Opcode::CONT;
                $rsv = 0; // RSV must be 0 in continuation frames.
            }

            $data = \substr($data, $length * $i, $length);
        }

        if ($compress) {
            /** @psalm-suppress PossiblyNullReference */
            $data = $this->compressionContext->compress($data, true);
        }

        return $this->write($data, $opcode, $rsv, true)
            ->catch(function (\Throwable $exception): void {
                $code = Code::ABNORMAL_CLOSE;
                $reason = 'Writing to the client failed';
                $this->close($code, $reason);
                throw new ClosedException('Client unexpectedly closed', $code, $reason, $exception);
            });
    }

    private function pushStream(ReadableStream $stream, int $opcode): Future
    {
        if (!$this->lastWrite) {
            $this->lastWrite = Future::complete(null);
        }

        // Setting $this->lastWrite will force subsequent sends to queue until this stream has ended.
        return $this->lastWrite = $thisWrite = $this->lastWrite->map(
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
    }

    private function sendStream(ReadableStream $stream, int $opcode): void
    {
        $rsv = 0;
        $compress = false;

        if ($this->compressionContext && $opcode === Opcode::TEXT) {
            $rsv |= $this->compressionContext->getRsv();
            $compress = true;
        }

        try {
            $buffer = $stream->read();

            if ($buffer === null) {
                $this->write('', $opcode, 0, true)->await();
                return;
            }

            $streamThreshold = $this->options->getStreamThreshold();

            while (($chunk = $stream->read()) !== null) {
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

                $this->write($buffer, $opcode, $rsv, false)->await();
                $opcode = Opcode::CONT;
                $rsv = 0; // RSV must be 0 in continuation frames.

                $buffer = $chunk;
            }

            if ($compress) {
                /** @psalm-suppress PossiblyNullReference */
                $buffer = $this->compressionContext->compress($buffer, true);
            }

            $this->write($buffer, $opcode, $rsv, true)->await();
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

    private function write(string $data, int $opcode, int $rsv = 0, bool $isFinal = true): Future
    {
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

    public function close(int $code = Code::NORMAL_CLOSE, string $reason = ''): array
    {
        if ($this->metadata->closedAt) {
            return [$this->metadata->closeCode, $this->metadata->closeReason];
        }

        \assert($code !== Code::NONE || $reason === '');

        $this->metadata->closedAt = self::$now;
        $this->metadata->closeCode = $code;
        $this->metadata->closeReason = $reason;

        try {
            $this->lastWrite?->await();
        } catch (\Throwable) {
            return [$this->metadata->closeCode, $this->metadata->closeReason];
        }

        $this->write($code !== Code::NONE ? \pack('n', $code) . $reason : '', Opcode::CLOSE)->ignore();

        if ($this->currentMessageEmitter) {
            $emitter = $this->currentMessageEmitter;
            $this->currentMessageEmitter = null;
            $emitter->error(new ClosedException(
                'Connection closed while streaming message body',
                $code,
                $reason
            ));
        }

        if ($this->nextMessageDeferred) {
            $deferred = $this->nextMessageDeferred;
            $this->nextMessageDeferred = null;

            match ($code) {
                Code::NORMAL_CLOSE, Code::NONE, => $deferred->complete(null),
                default => $deferred->error(new ClosedException(
                    'Connection closed abnormally while awaiting message',
                    $code,
                    $reason
                )),
            };
        }

        try {
            // Wait for peer close frame for configured number of seconds.
            $this->closeDeferred?->getFuture()
                ->await(new TimeoutCancellationToken($this->options->getClosePeriod()));
        } catch (\Throwable $exception) {
            // Failed to write close frame or to receive response frame, but we were disconnecting anyway.
        }

        $this->socket->close();
        $this->lastWrite = Future::complete(null);
        $this->lastEmit = null;

        $onClose = $this->onClose;
        $this->onClose = null;

        if ($onClose !== null) {
            foreach ($onClose as $callback) {
                EventLoop::queue(fn () => $callback($this, $code, $reason));
            }
        }

        unset(self::$clients[$this->metadata->id]);
        self::$heartbeatTimeouts->remove($this->metadata->id);

        if (empty(self::$clients)) {
            EventLoop::cancel(self::$watcher);
            self::$heartbeatTimeouts->clear();
        }

        return [$this->metadata->closeCode, $this->metadata->closeReason];
    }

    public function onClose(callable $callback): void
    {
        if ($this->onClose === null) {
            EventLoop::queue(fn () => $callback($this, $this->metadata->closeCode, $this->metadata->closeReason));
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
