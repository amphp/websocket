<?php declare(strict_types=1);

namespace Amp\Websocket\Parser;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Parser\Parser;
use Amp\Websocket\CloseCode;
use Amp\Websocket\Compression\CompressionContext;
use Amp\Websocket\Opcode;

final class Rfc6455Parser implements WebsocketParser
{
    use ForbidCloning;
    use ForbidSerialization;

    public const DEFAULT_TEXT_ONLY = false;
    public const DEFAULT_VALIDATE_UTF8 = true;
    public const DEFAULT_MESSAGE_SIZE_LIMIT = (2 ** 20) * 10; // 10MB
    public const DEFAULT_FRAME_SIZE_LIMIT = 2 ** 20; // 1MB

    private readonly Parser $parser;

    private ?Opcode $opcode = null;

    private bool $compress = false;

    public function __construct(
        WebsocketFrameHandler $frameHandler,
        private readonly bool $masked,
        private readonly ?CompressionContext $compressionContext = null,
        bool $textOnly = self::DEFAULT_TEXT_ONLY,
        bool $validateUtf8 = self::DEFAULT_VALIDATE_UTF8,
        int $messageSizeLimit = self::DEFAULT_MESSAGE_SIZE_LIMIT,
        int $frameSizeLimit = self::DEFAULT_FRAME_SIZE_LIMIT,
    ) {
        $this->parser = new Parser(self::parse(
            $frameHandler,
            $masked,
            $compressionContext,
            $textOnly,
            $validateUtf8,
            $messageSizeLimit,
            $frameSizeLimit,
        ));
    }

    public function push(string $data): void
    {
        $this->parser->push($data);
    }

    public function cancel(): void
    {
        $this->parser->cancel();
    }

    public function compileFrame(Opcode $opcode, string $data, bool $isFinal): string
    {
        \assert($this->assertState($opcode));

        if ($this->compressionContext && $opcode === Opcode::Text) {
            $this->compress = !$isFinal || \strlen($data) > $this->compressionContext->getCompressionThreshold();
        }

        $this->opcode = match ($opcode) {
            Opcode::Text, Opcode::Binary => $opcode,
            default => $this->opcode,
        };

        $rsv = 0;

        try {
            if ($this->compressionContext && $this->compress && match ($opcode) {
                Opcode::Text, Opcode::Continuation => true,
                default => false,
            }) {
                if ($opcode !== Opcode::Continuation) {
                    $rsv |= $this->compressionContext->getRsv();
                }

                $data = $this->compressionContext->compress($data, $isFinal);
            }
        } catch (\Throwable $exception) {
            $isFinal = true; // Reset state in finally.
            throw $exception;
        } finally {
            if ($isFinal) {
                $this->opcode = null;
                $this->compress = false;
            }
        }

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

    private function assertState(Opcode $opcode): bool
    {
        if ($this->opcode && match ($opcode) {
            Opcode::Text, Opcode::Binary => true,
            default => false,
        }) {
            throw new \ValueError(\sprintf(
                'Next frame must be a continuation or control frame; got %s (0x%d) while sending %s (0x%d)',
                $opcode->name,
                \dechex($opcode->value),
                $this->opcode->name,
                \dechex($opcode->value),
            ));
        }

        if (!$this->opcode && $opcode === Opcode::Continuation) {
            throw new \ValueError('Cannot send a continuation frame without sending a non-final text or binary frame');
        }

        return true;
    }

    /**
     * A stateful generator websocket frame parser.
     *
     * @return \Generator<int, int, string, void>
     *
     * @psalm-suppress InvalidReturnType Psalm infers "never" as the return type.
     */
    private static function parse(
        WebsocketFrameHandler $frameHandler,
        bool $masked,
        ?CompressionContext $compressionContext,
        bool $textOnly,
        bool $validateUtf8,
        int $messageSizeLimit,
        int $frameSizeLimit,
    ): \Generator {
        $doUtf8Validation = $validateUtf8;
        $compressedFlag = $compressionContext?->getRsv() ?? 0;

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
                throw new ParserException(CloseCode::PROTOCOL_ERROR, 'Use of reserved non-control frame opcode');
            }

            if ($opcode >= 11 && $opcode <= 15) {
                throw new ParserException(CloseCode::PROTOCOL_ERROR, 'Use of reserved control frame opcode');
            }

            $opcode = Opcode::tryFrom($opcode);
            if (!$opcode) {
                throw new ParserException(CloseCode::PROTOCOL_ERROR, 'Invalid opcode');
            }

            $isControlFrame = $opcode->isControlFrame();

            if ($isControlFrame || $opcode === Opcode::Continuation) { // Control and continuation frames
                if ($rsv !== 0) {
                    throw new ParserException(
                        CloseCode::PROTOCOL_ERROR,
                        'RSV must be 0 for control or continuation frames',
                    );
                }
            } else { // Text and binary frames
                if ($rsv !== 0 && (!$compressionContext || $rsv & ~$compressedFlag)) {
                    throw new ParserException(
                        CloseCode::PROTOCOL_ERROR,
                        'Invalid RSV value for negotiated extensions',
                    );
                }

                $doUtf8Validation = $validateUtf8 && $opcode === Opcode::Text;
                $compressed = (bool) ($rsv & $compressedFlag);
            }

            if ($frameLength === 0x7E) {
                [, $frameLength] = \unpack('n', yield 2);
            } elseif ($frameLength === 0x7F) {
                [, $highBytes, $lowBytes] = \unpack('N2', yield 8);

                if (\PHP_INT_MAX === 0x7fffffff) {
                    if ($highBytes !== 0 || $lowBytes < 0) {
                        throw new ParserException(
                            CloseCode::MESSAGE_TOO_LARGE,
                            'Received payload exceeds maximum allowable size'
                        );
                    }
                    $frameLength = $lowBytes;
                } else {
                    $frameLength = ($highBytes << 32) | $lowBytes;
                    if ($frameLength < 0) {
                        throw new ParserException(
                            CloseCode::PROTOCOL_ERROR,
                            'Most significant bit of 64-bit length field set'
                        );
                    }
                }
            }

            if ($frameLength > 0 && $isMasked === $masked) {
                throw new ParserException(
                    CloseCode::PROTOCOL_ERROR,
                    'Payload mask error'
                );
            }

            if ($isControlFrame) {
                if (!$final) {
                    throw new ParserException(
                        CloseCode::PROTOCOL_ERROR,
                        'Illegal control frame fragmentation'
                    );
                }

                if ($frameLength > 125) {
                    throw new ParserException(
                        CloseCode::PROTOCOL_ERROR,
                        'Control frame payload must be of maximum 125 bytes or less'
                    );
                }
            }

            if ($frameSizeLimit && $frameLength > $frameSizeLimit) {
                throw new ParserException(
                    CloseCode::MESSAGE_TOO_LARGE,
                    'Received payload exceeds maximum allowable size'
                );
            }

            if ($messageSizeLimit && ($frameLength + $dataMsgBytesRecd) > $messageSizeLimit) {
                throw new ParserException(
                    CloseCode::MESSAGE_TOO_LARGE,
                    'Received payload exceeds maximum allowable size'
                );
            }

            if ($isMasked) {
                $maskingKey = yield 4;
            }

            if ($frameLength) {
                $payload = yield $frameLength;
            }

            if ($isMasked) {
                // This is memory hungry, but it's ~70x faster than iterating byte-by-byte
                // over the masked string. Deal with it; manual iteration is untenable.
                /** @psalm-suppress InvalidOperand String operands expected. */
                $payload ^= \str_repeat($maskingKey, ($frameLength + 3) >> 2);
            }

            if ($isControlFrame) {
                $frameHandler->handleFrame($opcode, $payload, true);
                continue;
            }

            if ($textOnly && $opcode === Opcode::Binary) {
                throw new ParserException(
                    CloseCode::UNACCEPTABLE_TYPE,
                    'BINARY opcodes (0x02) not accepted'
                );
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
                    throw new ParserException(
                        CloseCode::PROTOCOL_ERROR,
                        'Invalid compressed data'
                    );
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
                    throw new ParserException(
                        CloseCode::INCONSISTENT_FRAME_DATA_TYPE,
                        'Invalid TEXT data; UTF-8 required'
                    );
                }
            }

            if ($final) {
                $dataMsgBytesRecd = 0;
            }

            $frameHandler->handleFrame($opcode, $payload, $final);
        }
    }
}
