<?php declare(strict_types=1);

namespace Amp\Websocket\Test;

use Amp\Websocket\Opcode;

// 4-byte "random" mask
const MASK = "\xF4\x37\x7A\x9C";

function compile(Opcode $opcode, bool $masked, bool $isFinal, string $data = "", int $rsv = 0b000): string
{
    $length = \strlen($data);
    $w = \chr(($isFinal << 7) | ($rsv << 4) | $opcode->value);

    $maskFlag = $masked ? 0x80 : 0;

    if ($length > 0xFFFF) {
        $w .= \chr(0x7F | $maskFlag) . \pack('J', $length);
    } elseif ($length > 0x7D) {
        $w .= \chr(0x7E | $maskFlag) . \pack('n', $length);
    } else {
        $w .= \chr($length | $maskFlag);
    }

    if ($masked) {
        return $w . MASK . ($data ^ \str_repeat(MASK, ($length + 3) >> 2));
    }

    return $w . $data;
}
