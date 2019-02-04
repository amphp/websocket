<?php

namespace Amp\Websocket;

use Amp\ByteStream\IteratorStream;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Emitter;
use Amp\Failure;
use Amp\Promise;
use Amp\Socket\Socket;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;
use function Amp\call;

final class Rfc6455Client implements Client
{
    /** @var \Generator|null */
    private $parser;

    /** @var PsrLogger */
    private $logger;

    /** @var Options */
    private $options;

    /** @var int */
    private $id;

    /** @var \Amp\Socket\Socket */
    private $socket;

    /** @var \Amp\Promise|null */
    private $lastWrite;

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

    private $pingCount = 0;
    private $pongCount = 0;

    /** @var int */
    private $connectedAt;

    /** @var int|null */
    private $closeCode;

    /** @var string|null */
    private $closeReason;

    private $closedAt = 0;
    private $lastReadAt = 0;
    private $lastSentAt = 0;
    private $lastDataReadAt = 0;
    private $lastDataSentAt = 0;
    private $bytesRead = 0;
    private $bytesSent = 0;
    private $framesRead = 0;
    private $framesSent = 0;
    private $messagesRead = 0;
    private $messagesSent = 0;

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
        $this->connectedAt = \time();

        $this->socket = $socket;
        $this->options = $options;
        $this->id = (int) $socket->getResource();
        $this->masked = $masked;
        $this->compressionContext = $compression;
    }

    public function setup(): callable
    {
        if ($this->parser) {
            throw new \Error('Client has already been setup');
        }

        $this->parser = $this->parser();

        return function (string $chunk): int {
            if ($this->closedAt) {
                return 0;
            }

            $this->lastReadAt = \time();
            $this->bytesRead += \strlen($chunk);

            $frames = (int) $this->parser->send($chunk);
            $this->framesRead += $frames;

            return $frames;
        };
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

        if ($this->closedAt) {
            return new Failure(
                new ClosedException('The connection was closed', $this->closeCode, $this->closeReason)
            );
        }

        $this->nextMessageDeferred = new Deferred;

        return $this->nextMessageDeferred->promise();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUnansweredPingCount(): int
    {
        return $this->pingCount - $this->pongCount;
    }

    public function isConnected(): bool
    {
        return !$this->closedAt;
    }

    public function getLocalAddress(): string
    {
        return $this->socket->getLocalAddress();
    }

    public function getRemoteAddress(): string
    {
        return $this->socket->getRemoteAddress();
    }

    public function getInfo(): array
    {
        return [
            'bytes_read'        => $this->bytesRead,
            'bytes_sent'        => $this->bytesSent,
            'frames_read'       => $this->framesRead,
            'frames_sent'       => $this->framesSent,
            'messages_read'     => $this->messagesRead,
            'messages_sent'     => $this->messagesSent,
            'connected_at'      => $this->connectedAt,
            'closed_at'         => $this->closedAt,
            'close_code'        => $this->closeCode,
            'close_reason'      => $this->closeReason,
            'last_read_at'      => $this->lastReadAt,
            'last_sent_at'      => $this->lastSentAt,
            'last_data_read_at' => $this->lastDataReadAt,
            'last_data_sent_at' => $this->lastDataSentAt,
            'ping_count'        => $this->pingCount,
            'pong_count'        => $this->pongCount,
        ];
    }

    private function onData(int $opcode, string $data, bool $terminated): void
    {
        // something went that wrong that we had to close... if parser has anything left, we don't care!
        if ($this->closedAt) {
            return;
        }

        $this->lastDataReadAt = \time();

        if (!$this->currentMessageEmitter) {
            if ($opcode === Opcode::CONT) {
                $this->onError(
                    Code::PROTOCOL_ERROR,
                    'Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY'
                );
                return;
            }

            $this->currentMessageEmitter = new Emitter;
            $message = new Message(new IteratorStream($this->currentMessageEmitter->iterate()), $opcode === Opcode::BIN);

            if ($this->nextMessageDeferred) {
                $deferred = $this->nextMessageDeferred;
                $this->nextMessageDeferred = null;
                $deferred->resolve($message);
            } else {
                $this->messages[] = $message;
            }
        } elseif ($opcode !== Opcode::CONT) {
            $this->onError(
                Code::PROTOCOL_ERROR,
                'Illegal data type opcode after unfinished previous data type frame; opcode MUST be CONTINUATION'
            );
            return;
        }

        $this->currentMessageEmitter->emit($data);

        if ($terminated) {
            $emitter = $this->currentMessageEmitter;
            $this->currentMessageEmitter = null;
            $emitter->complete();

            ++$this->messagesRead;
        }
    }

    private function onControlFrame(int $opcode, string $data): void
    {
        // something went that wrong that we had to close... if parser has anything left, we don't care!
        if ($this->closedAt) {
            return;
        }

        switch ($opcode) {
            case Opcode::CLOSE:
                if ($this->closedAt) {
                    break;
                }

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
                        || ($code >= 1004 && $code <= 1006) // Should not be sent over wire.
                        || (!$this->masked && $code >= 1014 && $code <= 1016) // Should only be sent by server.
                        || ($code >= 1017 && $code <= 1999) // Reserved for future use
                        || ($code >= 2000 && $code <= 2999) // Reserved for WebSocket extensions.
                        || $code >= 5000 // 3000-3999 for libraries, 4000-4999 for applications, >= 5000 invalid.
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
                // We need a min() here, else someone might just send a pong frame with a very high pong count and
                // leave TCP connection in open state... Then we'd accumulate connections which never are cleaned up...
                $this->pongCount = \min($this->pingCount, $data);
                break;
        }
    }

    private function onError(int $code, string $reason): void
    {
        $this->close($code, $reason);
    }

    public function send(string $data): Promise
    {
        \assert(\preg_match('//u', $data), 'Text data must be UTF-8');
        return $this->lastWrite = new Coroutine($this->post($data, Opcode::TEXT));
    }

    public function sendBinary(string $data): Promise
    {
        return $this->lastWrite = new Coroutine($this->post($data, Opcode::BIN));
    }

    public function ping(): Promise
    {
        ++$this->pingCount;
        return $this->write((string) $this->pingCount, Opcode::PING);
    }

    private function post(string $data, int $opcode): \Generator
    {
        if ($this->lastWrite) {
            yield $this->lastWrite;
        }

        ++$this->messagesSent;

        $rsv = 0;

        if ($this->compressionContext
            && $opcode === Opcode::TEXT
            && \strlen($data) > $this->compressionContext->getCompressionThreshold()
        ) {
            $data = $this->compressionContext->compress($data);
            $rsv |= $this->compressionContext->getRsv();
        }

        try {
            $bytes = 0;

            if (\strlen($data) > $this->options->getFrameSplitThreshold()) {
                $len = \strlen($data);
                $slices = \ceil($len / $this->options->getFrameSplitThreshold());
                $chunks = \str_split($data, \ceil($len / $slices));
                $final = \array_pop($chunks);
                foreach ($chunks as $chunk) {
                    $bytes += yield $this->write($chunk, $opcode, $rsv, false);
                    $opcode = Opcode::CONT;
                    $rsv = 0; // RSV must be 0 in continuation frames.
                }
                $bytes += yield $this->write($final, $opcode, $rsv, true);
            } else {
                $bytes = yield $this->write($data, $opcode, $rsv);
            }
        } catch (\Throwable $exception) {
            $this->close(Code::ABNORMAL_CLOSE, 'Writing to the client failed');
            $this->lastWrite = null; // prevent storing a cyclic reference
        }

        return $bytes;
    }

    private function write(string $msg, int $opcode, int $rsv = 0, bool $fin = true): Promise
    {
        if ($this->closedAt) {
            return new Success(0);
        }

        $frame = $this->compile($msg, $opcode, $rsv, $fin);

        ++$this->framesSent;
        $this->bytesSent += \strlen($frame);
        $this->lastSentAt = \time();

        return $this->socket->write($frame);
    }

    private function compile(string $data, int $opcode, int $rsv, bool $final): string
    {
        $length = \strlen($data);
        $w = \chr(($final << 7) | ($rsv << 4) | $opcode);

        if ($this->masked) {
            if ($length > 0xFFFF) {
                $w .= "\xFF" . \pack('J', $length);
            } elseif ($length > 0x7D) {
                $w .= "\xFE" . \pack('n', $length);
            } else {
                $w .= \chr($length | 0x80);
            }

            $mask = \pack('N', \random_int(\PHP_INT_MIN, \PHP_INT_MAX));
            return $w . $mask . ($data ^ \str_repeat($mask, ($length + 3) >> 2));
        }

        if ($length > 0xFFFF) {
            $w .= "\x7F" . \pack('J', $length);
        } elseif ($length > 0x7D) {
            $w .= "\x7E" . \pack('n', $length);
        } else {
            $w .= \chr($length);
        }

        return $w . $data;
    }

    public function close(int $code = Code::NORMAL_CLOSE, string $reason = ''): Promise
    {
        if ($this->closedAt) {
            return new Success(0);
        }

        return call(function () use ($code, $reason) {
            $this->closeCode = $code;
            $this->closeReason = $reason;

            try {
                \assert($code !== Code::NONE || $reason === '');
                $promise = $this->write($code !== Code::NONE ? \pack('n', $code) . $reason : '', Opcode::CLOSE);

                $this->closedAt = \time();

                if ($this->currentMessageEmitter || $this->nextMessageDeferred) {
                    $exception = new ClosedException('Connection unexpectedly closed', $code, $reason);

                    if ($this->currentMessageEmitter) {
                        $emitter = $this->currentMessageEmitter;
                        $this->currentMessageEmitter = null;
                        $emitter->fail($exception);
                    }

                    if ($this->nextMessageDeferred) {
                        $deferred = $this->nextMessageDeferred;
                        $this->nextMessageDeferred = null;
                        $deferred->fail($exception);
                    }
                }

                $bytes = yield $promise;
            } finally {
                $this->socket->close();
                $this->parser = null;
                $this->lastWrite = null;
            }

            return $bytes;
        });
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
        $savedOpcode = null;
        $compressed = false;

        $buffer = yield;
        $offset = 0;
        $bufferSize = \strlen($buffer);
        $frames = 0;

        while (true) {
            $payload = ''; // Free memory from last frame payload.

            if ($bufferSize < 2) {
                $buffer = \substr($buffer, $offset);
                $offset = 0;
                do {
                    $buffer .= yield $frames;
                    $bufferSize = \strlen($buffer);
                    $frames = 0;
                } while ($bufferSize < 2);
            }

            $firstByte = \ord($buffer[$offset]);
            $secondByte = \ord($buffer[$offset + 1]);

            $offset += 2;
            $bufferSize -= 2;

            $final = (bool) ($firstByte & 0b10000000);
            $rsv = ($firstByte & 0b01110000) >> 4;
            $opcode = $firstByte & 0b00001111;
            $isMasked = (bool) ($secondByte & 0b10000000);
            $maskingKey = null;
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
                if ($bufferSize < 2) {
                    $buffer = \substr($buffer, $offset);
                    $offset = 0;
                    do {
                        $buffer .= yield $frames;
                        $bufferSize = \strlen($buffer);
                        $frames = 0;
                    } while ($bufferSize < 2);
                }

                $frameLength = \unpack('n', $buffer[$offset] . $buffer[$offset + 1])[1];
                $offset += 2;
                $bufferSize -= 2;
            } elseif ($frameLength === 0x7F) {
                if ($bufferSize < 8) {
                    $buffer = \substr($buffer, $offset);
                    $offset = 0;
                    do {
                        $buffer .= yield $frames;
                        $bufferSize = \strlen($buffer);
                        $frames = 0;
                    } while ($bufferSize < 8);
                }

                $lengthLong32Pair = \unpack('N2', \substr($buffer, $offset, 8));
                $offset += 8;
                $bufferSize -= 8;

                if (PHP_INT_MAX === 0x7fffffff) {
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
                if ($bufferSize < 4) {
                    $buffer = \substr($buffer, $offset);
                    $offset = 0;
                    do {
                        $buffer .= yield $frames;
                        $bufferSize = \strlen($buffer);
                        $frames = 0;
                    } while ($bufferSize < 4);
                }

                $maskingKey = \substr($buffer, $offset, 4);
                $offset += 4;
                $bufferSize -= 4;
            }

            while ($bufferSize < $frameLength) {
                $chunk = yield $frames;
                $buffer .= $chunk;
                $bufferSize += \strlen($chunk);
                $frames = 0;
            }

            $payload = \substr($buffer, $offset, $frameLength);
            $offset += $frameLength;
            $bufferSize -= $frameLength;

            if ($isMasked) {
                // This is memory hungry but it's ~70x faster than iterating byte-by-byte
                // over the masked string. Deal with it; manual iteration is untenable.
                $payload ^= \str_repeat($maskingKey, ($frameLength + 3) >> 2);
            }

            if ($isControlFrame) {
                $this->onControlFrame($opcode, $payload);
                $frames++;
                continue;
            }

            $dataMsgBytesRecd += $frameLength;

            if ($savedBuffer !== '') {
                $payload = $savedBuffer . $payload;
                $savedBuffer = '';
            }

            if ($compressed) {
                if (!$final) {
                    $savedBuffer = $payload;
                    $frames++;

                    if ($opcode !== Opcode::CONT) {
                        $savedOpcode = $opcode;
                    }

                    continue;
                }

                $payload = $compressionContext->decompress($payload);

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

                if (!$valid) {
                    $this->onError(
                        Code::INCONSISTENT_FRAME_DATA_TYPE,
                        'Invalid TEXT data; UTF-8 required'
                    );
                    return;
                }
            }

            $opcode = $savedOpcode ?? $opcode;

            if ($final) {
                $dataMsgBytesRecd = 0;
                $savedOpcode = null;
            }

            $this->onData($opcode, $payload, $final);
            $frames++;
        }
    }
}
