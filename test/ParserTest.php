<?php

namespace Amp\Websocket\Test;

use Amp\Loop;
use Amp\PHPUnit\TestCase;
use Amp\Socket\Socket;
use Amp\Websocket\ClosedException;
use Amp\Websocket\Code;
use Amp\Websocket\Message;
use Amp\Websocket\Opcode;
use Amp\Websocket\Options;
use Amp\Websocket\Rfc6455Client;

class ParserTest extends TestCase
{
    public static function compile(int $opcode, bool $final, string $message = "", int $rsv = 0b000): string
    {
        $len = \strlen($message);

        // FRRROOOO per RFC 6455 Section 5.2
        $w = \chr(($final << 7) | ($rsv << 4) | $opcode);

        // length as bits 2-2/4/6, with masking bit set
        if ($len > 0xFFFF) {
            $w .= "\xFF" . \pack('J', $len);
        } elseif ($len > 0x7D) {
            $w .= "\xFE" . \pack('n', $len);
        } else {
            $w .= \chr($len | 0x80);
        }

        // 4 bit mask (random)
        $mask = "\xF4\x37\x7A\x9C";
        // apply mask
        $masked = $message ^ \str_repeat($mask, ($len + 3) >> 2);

        return $w . $mask . $masked;
    }

    /**
     * @dataProvider provideParserData
     */
    public function testParser(
        string $chunk,
        int $frameCount,
        ?string $data,
        bool $isBinary,
        ?string $reason = null,
        ?int $code = null
    ): void {
        Loop::run(function () use ($chunk, $frameCount, $data, $isBinary, $code, $reason) {
            $client = new Rfc6455Client($this->createMock(Socket::class), new Options, false);

            $parser = $client->setup();

            $frames = $parser($chunk);
            $client->close();

            $this->assertSame($frameCount, $frames);

            try {
                while ($message = yield $client->receive()) {
                    \assert($message instanceof Message);
                    $this->assertSame($data, yield $message->buffer());
                    $this->assertSame($isBinary, $message->isBinary());
                }
            } catch (ClosedException $exception) {
                $this->assertSame($code ?? Code::NORMAL_CLOSE, $exception->getCode());
                $this->assertSame($reason ?? '', $exception->getReason());
                return;
            }
        });
    }

    public function provideParserData(): array
    {
        $return = [];

        // 0-13 -- basic text and binary frames with fixed lengths -------------------------------->

        foreach ([0 /* 0-1 */, 125 /* 2-3 */, 126 /* 4-5 */, 127 /* 6-7 */, 128 /* 8-9 */, 65535 /* 10-11 */, 65536 /* 12-13 */] as $length) {
            $data = \str_repeat("*", $length);
            foreach ([Opcode::TEXT, Opcode::BIN] as $optype) {
                $input = static::compile($optype, true, $data);
                $return[] = [$input, 1, $data, $optype === Opcode::BIN];
            }
        }
        //
        // 14-17 - basic control frame parsing ---------------------------------------------------->

        foreach (["" /* 14 */, "Hello world!" /* 15 */, "\x00\xff\xfe\xfd\xfc\xfb\x00\xff" /* 16 */, \str_repeat("*", 125) /* 17 */] as $data) {
            $input = static::compile(Opcode::PING, true, $data);
            $return[] = [$input, 1, null, false];
        }

        // 18 ---- error conditions: using a non-terminated frame with a control opcode ----------->

        $input = static::compile(Opcode::PING, false);
        $return[] = [$input, 0, null, false, "Illegal control frame fragmentation", Code::PROTOCOL_ERROR];

        // 19 ---- error conditions: using a standalone continuation frame with fin = true -------->

        $input = static::compile(Opcode::CONT, true);
        $return[] = [$input, 1, null, false, "Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY", Code::PROTOCOL_ERROR];

        // 20 ---- error conditions: using a standalone continuation frame with fin = false ------->

        $input = static::compile(Opcode::CONT, false);
        $return[] = [$input, 1, null, false, "Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY", Code::PROTOCOL_ERROR];

        // 21 ---- error conditions: using a continuation frame after a finished text frame ------->

        $input = static::compile(Opcode::TEXT, true, "Hello, world!") . static::compile(Opcode::CONT, true);
        $return[] = [$input, 2, "Hello, world!", false, "Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY", Code::PROTOCOL_ERROR];

        // 22-29 - continuation frame parsing ----------------------------------------------------->

        foreach ([[1, 0] /* 22-23 */, [126, 125] /* 24-25 */, [32767, 32769] /* 26-27 */, [32768, 32769] /* 28-29 */] as list($len1, $len2)) {
            // simple
            $input = static::compile(Opcode::TEXT, false, \str_repeat("*", $len1)) . static::compile(Opcode::CONT, true, \str_repeat("*", $len2));
            $return[] = [$input, 2, \str_repeat("*", $len1 + $len2), false];

            // with interleaved control frame
            $input = static::compile(Opcode::TEXT, false, \str_repeat("*", $len1)) . static::compile(Opcode::PING, true, "foo") . static::compile(Opcode::CONT, true, \str_repeat("*", $len2));
            $return[] = [$input, 3, \str_repeat("*", $len1 + $len2), false];
        }

        // 30 ---- error conditions: using a text frame after a not finished text frame ----------->

        $input = static::compile(Opcode::TEXT, false, "Hello, world!") . static::compile(Opcode::TEXT, true, "uhm, no!");
        $return[] = [$input, 2, null, false, "Illegal data type opcode after unfinished previous data type frame; opcode MUST be CONTINUATION", Code::PROTOCOL_ERROR];

        // 31 ---- utf-8 validation must resolve for large utf-8 msgs ----------------------------->

        $data = "H" . \str_repeat("ö", 32770);
        $input = static::compile(Opcode::TEXT, false, \substr($data, 0, 32769)) . static::compile(Opcode::CONT, true, \substr($data, 32769));
        $return[] = [$input, 2, $data, false];

        // 32 ---- utf-8 validation must resolve for interrupted utf-8 across frame boundary ------>

        $data = "H" . \str_repeat("ö", 32770);
        $input = static::compile(Opcode::TEXT, false, \substr($data, 0, 32768)) . static::compile(Opcode::CONT, true, \substr($data, 32768));
        $return[] = [$input, 2, $data, false];

        // 33 ---- utf-8 validation must fail for bad utf-8 data (single frame) ------------------->

        $input = static::compile(Opcode::TEXT, true, \substr(\str_repeat("ö", 2), 1));
        $return[] = [$input, 0, null, false, "Invalid TEXT data; UTF-8 required", Code::INCONSISTENT_FRAME_DATA_TYPE];

        // 34 ---- utf-8 validation must fail for bad utf-8 data (multiple small frames) ---------->

        $data = "H" . \str_repeat("ö", 3);
        $input = static::compile(Opcode::TEXT, false, \substr($data, 0, 2)) . static::compile(Opcode::CONT, true, \substr($data, 3));
        $return[] = [$input, 0, null, false, "Invalid TEXT data; UTF-8 required", Code::INCONSISTENT_FRAME_DATA_TYPE];

        // 35 ---- utf-8 validation must fail for bad utf-8 data (multiple big frames) ------------>

        $data = "H" . \str_repeat("ö", 40000);
        $input = static::compile(Opcode::TEXT, false, \substr($data, 0, 32767)) . static::compile(Opcode::CONT, false, \substr($data, 32768));
        $return[] = [$input, 0, null, false, "Invalid TEXT data; UTF-8 required", Code::INCONSISTENT_FRAME_DATA_TYPE];

        // 36 ---- error conditions: using a too large payload with a control opcode -------------->

        $input = static::compile(Opcode::PING, true, \str_repeat("*", 126));
        $return[] = [$input, 0, null, false, "Control frame payload must be of maximum 125 bytes or less", Code::PROTOCOL_ERROR];

        // 37 ---- error conditions: unmasked data ------------------------------------------------>

        $input = \substr(static::compile(Opcode::PING, true, \str_repeat("*", 125)), 0, -4) & ("\xFF\x7F" . \str_repeat("\xFF", 0xFF));
        $return[] = [$input, 0, null, false, "Payload mask error", Code::PROTOCOL_ERROR];

        // 38 ---- error conditions: too large frame (> 2^63 bit) --------------------------------->

        $input = static::compile(Opcode::BIN, true, \str_repeat("*", 65536)) | ("\x00\x00\x80" . \str_repeat("\x00", 0xFF));
        $return[] = [$input, 0, null, true, "Most significant bit of 64-bit length field set", Code::PROTOCOL_ERROR];


        // 39 ---- utf-8 must be accepted for interrupted text with interleaved control frame ----->

        $data = "H" . \str_repeat("ö", 32770);
        $input = static::compile(Opcode::TEXT, false, \substr($data, 0, 32768)) . static::compile(Opcode::PING, true, "foo") . static::compile(Opcode::CONT, true, \substr($data, 32768));
        $return[] = [$input, 3, $data, false];

        // x -------------------------------------------------------------------------------------->

        return $return;
    }
}
