<?php declare(strict_types=1);

namespace Amp\Websocket\Parser;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Websocket\Compression\CompressionContext;
use Amp\Websocket\Opcode;

final class Rfc6455FrameCompiler implements WebsocketFrameCompiler
{
    use ForbidCloning;
    use ForbidSerialization;

    private ?Opcode $opcode = null;

    private bool $compress = false;

    public function __construct(
        private readonly bool $masked,
        private readonly ?CompressionContext $compressionContext = null,
    ) {
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
}
