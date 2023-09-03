<?php declare(strict_types=1);

namespace Amp\Websocket\Test;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket\Socket;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\Rfc6455Client;
use Amp\Websocket\WebsocketCloseCode;
use Amp\Websocket\WebsocketClosedException;
use Amp\Websocket\WebsocketFrameType;
use function Amp\delay;

class ParserTest extends AsyncTestCase
{
    /**
     * @dataProvider provideParserData
     */
    public function testParser(
        string $chunk,
        ?string $data,
        bool $isBinary,
        ?string $reason = null,
        ?int $code = null
    ): void {
        $socket = $this->createMock(Socket::class);
        $socket->method('read')
            ->willReturnCallback(function () use ($chunk): ?string {
                static $initial = true;

                if ($initial) {
                    $initial = false;
                    return $chunk;
                }

                delay(0); // Tick event loop before marking as closed.
                return null;
            });

        $client = new Rfc6455Client($socket, masked: false, parserFactory: new Rfc6455ParserFactory());

        try {
            while ($message = $client->receive()) {
                $this->assertSame($data, $message->buffer());
                $this->assertSame(!$isBinary, $message->isText());
                $this->assertSame($isBinary, $message->isBinary());
                $client->close();
            }
        } catch (WebsocketClosedException $exception) {
            $this->assertSame($code, $exception->getCode());
            $this->assertSame($reason, $exception->getReason());
        }

        $this->assertSame($code ?? WebsocketCloseCode::NORMAL_CLOSE, $client->getCloseCode());
        $this->assertSame($reason ?? '', $client->getCloseReason());
    }

    public function provideParserData(): array
    {
        $return = [];

        // 0-13 -- basic text and binary frames with fixed lengths -------------------------------->

        foreach ([0 /* 0-1 */, 125 /* 2-3 */, 126 /* 4-5 */, 127 /* 6-7 */, 128 /* 8-9 */, 65535 /* 10-11 */, 65536 /* 12-13 */] as $length) {
            $data = \str_repeat("*", $length);
            foreach ([WebsocketFrameType::Text, WebsocketFrameType::Binary] as $frameType) {
                $input = compile($frameType, true, true, $data);
                $return[] = [$input, $data, $frameType === WebsocketFrameType::Binary];
            }
        }
        //
        // 14-17 - basic control frame parsing ---------------------------------------------------->

        foreach (["" /* 14 */, "Hello world!" /* 15 */, "\x00\xff\xfe\xfd\xfc\xfb\x00\xff" /* 16 */, \str_repeat("*", 125) /* 17 */] as $data) {
            $input = compile(WebsocketFrameType::Ping, true, true, $data);
            $return[] = [$input, null, false, "TCP connection closed unexpectedly", WebsocketCloseCode::ABNORMAL_CLOSE];
        }

        // 18 ---- error conditions: using a non-terminated frame with a control opcode ----------->

        $input = compile(WebsocketFrameType::Ping, true, false);
        $return[] = [$input, null, false, "Illegal control frame fragmentation", WebsocketCloseCode::PROTOCOL_ERROR];

        // 19 ---- error conditions: using a standalone continuation frame with fin = true -------->

        $input = compile(WebsocketFrameType::Continuation, true, true);
        $return[] = [$input, null, false, "Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY", WebsocketCloseCode::PROTOCOL_ERROR];

        // 20 ---- error conditions: using a standalone continuation frame with fin = false ------->

        $input = compile(WebsocketFrameType::Continuation, true, false);
        $return[] = [$input, null, false, "Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY", WebsocketCloseCode::PROTOCOL_ERROR];

        // 21 ---- error conditions: using a continuation frame after a finished text frame ------->

        $input = compile(WebsocketFrameType::Text, true, true, "Hello, world!") . compile(WebsocketFrameType::Continuation, true, true);
        $return[] = [$input, "Hello, world!", false, "Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY", WebsocketCloseCode::PROTOCOL_ERROR];

        // 22-29 - continuation frame parsing ----------------------------------------------------->

        foreach ([[1, 0] /* 22-23 */, [126, 125] /* 24-25 */, [32767, 32769] /* 26-27 */, [32768, 32769] /* 28-29 */] as list($len1, $len2)) {
            // simple
            $input = compile(WebsocketFrameType::Text, true, false, \str_repeat("*", $len1)) . compile(WebsocketFrameType::Continuation, true, true, \str_repeat("*", $len2));
            $return[] = [$input, \str_repeat("*", $len1 + $len2), false];

            // with interleaved control frame
            $input = compile(WebsocketFrameType::Text, true, false, \str_repeat("*", $len1)) . compile(WebsocketFrameType::Ping, true, true, "foo") . compile(WebsocketFrameType::Continuation, true, true, \str_repeat("*", $len2));
            $return[] = [$input, \str_repeat("*", $len1 + $len2), false];
        }

        // 30 ---- error conditions: using a text frame after a not finished text frame ----------->

        $input = compile(WebsocketFrameType::Text, true, false, "Hello, world!") . compile(WebsocketFrameType::Text, true, true, "uhm, no!");
        $return[] = [$input, null, false, "Illegal data type opcode after unfinished previous data type frame; opcode MUST be CONTINUATION", WebsocketCloseCode::PROTOCOL_ERROR];

        // 31 ---- utf-8 validation must resolve for large utf-8 msgs ----------------------------->

        $data = "H" . \str_repeat("ö", 32770);
        $input = compile(WebsocketFrameType::Text, true, false, \substr($data, 0, 32769)) . compile(WebsocketFrameType::Continuation, true, true, \substr($data, 32769));
        $return[] = [$input, $data, false];

        // 32 ---- utf-8 validation must resolve for interrupted utf-8 across frame boundary ------>

        $data = "H" . \str_repeat("ö", 32770);
        $input = compile(WebsocketFrameType::Text, true, false, \substr($data, 0, 32768)) . compile(WebsocketFrameType::Continuation, true, true, \substr($data, 32768));
        $return[] = [$input, $data, false];

        // 33 ---- utf-8 validation must fail for bad utf-8 data (single frame) ------------------->

        $input = compile(WebsocketFrameType::Text, true, true, \substr(\str_repeat("ö", 2), 1));
        $return[] = [$input, null, false, "Invalid TEXT data; UTF-8 required", WebsocketCloseCode::INCONSISTENT_FRAME_DATA_TYPE];

        // 34 ---- utf-8 validation must fail for bad utf-8 data (multiple small frames) ---------->

        $data = "H" . \str_repeat("ö", 3);
        $input = compile(WebsocketFrameType::Text, true, false, \substr($data, 0, 2)) . compile(WebsocketFrameType::Continuation, true, true, \substr($data, 3));
        $return[] = [$input, null, false, "Invalid TEXT data; UTF-8 required", WebsocketCloseCode::INCONSISTENT_FRAME_DATA_TYPE];

        // 35 ---- utf-8 validation must fail for bad utf-8 data (multiple big frames) ------------>

        $data = "H" . \str_repeat("ö", 40000);
        $input = compile(WebsocketFrameType::Text, true, false, \substr($data, 0, 32767)) . compile(WebsocketFrameType::Continuation, true, false, \substr($data, 32768));
        $return[] = [$input, null, false, "Invalid TEXT data; UTF-8 required", WebsocketCloseCode::INCONSISTENT_FRAME_DATA_TYPE];

        // 36 ---- error conditions: using a too large payload with a control opcode -------------->

        $input = compile(WebsocketFrameType::Ping, true, true, \str_repeat("*", 126));
        $return[] = [$input, null, false, "Control frame payload must be of maximum 125 bytes or less", WebsocketCloseCode::PROTOCOL_ERROR];

        // 37 ---- error conditions: unmasked data ------------------------------------------------>

        $input = \substr(compile(WebsocketFrameType::Ping, true, true, \str_repeat("*", 125)), 0, -4) & ("\xFF\x7F" . \str_repeat("\xFF", 0xFF));
        $return[] = [$input, null, false, "Payload mask error", WebsocketCloseCode::PROTOCOL_ERROR];

        // 38 ---- error conditions: too large frame (> 2^63 bit) --------------------------------->

        $input = compile(WebsocketFrameType::Binary, true, true, \str_repeat("*", 65536)) | ("\x00\x00\x80" . \str_repeat("\x00", 0xFF));
        $return[] = [$input, null, true, "Most significant bit of 64-bit length field set", WebsocketCloseCode::PROTOCOL_ERROR];

        // 39 ---- utf-8 must be accepted for interrupted text with interleaved control frame ----->

        $data = "H" . \str_repeat("ö", 32770);
        $input = compile(WebsocketFrameType::Text, true, false, \substr($data, 0, 32768)) . compile(WebsocketFrameType::Ping, true, true, "foo") . compile(WebsocketFrameType::Continuation, true, true, \substr($data, 32768));
        $return[] = [$input, $data, false];

        // 40 ---- close frame -------------------------------------------------------------------->

        $input = compile(WebsocketFrameType::Close, true, true);
        $return[] = [$input, null, true, '', WebsocketCloseCode::NONE];

        // 41 ---- invalid close code ------------------------------------------------------------->

        $input = compile(WebsocketFrameType::Close, true, true, \pack('n', 5000));
        $return[] = [$input, null, true, "Invalid close code", WebsocketCloseCode::PROTOCOL_ERROR];

        // 42 ---- invalid close payload ---------------------------------------------------------->

        $input = compile(WebsocketFrameType::Close, true, true, "0");
        $return[] = [$input, null, true, "Close code must be two bytes", WebsocketCloseCode::PROTOCOL_ERROR];

        // 43 ---- non-utf-8 close payload -------------------------------------------------------->

        $input = compile(WebsocketFrameType::Close, true, true, \pack('n', WebsocketCloseCode::NORMAL_CLOSE) . "\x80\x00");
        $return[] = [$input, null, true, "Close reason must be valid UTF-8", WebsocketCloseCode::INCONSISTENT_FRAME_DATA_TYPE];

        // 44 ---- pong frame --------------------------------------------------------------------->

        $input = compile(WebsocketFrameType::Pong, true, true, "123");
        $return[] = [$input, null, true, "TCP connection closed unexpectedly", WebsocketCloseCode::ABNORMAL_CLOSE];

        // 45 ---- pong frame with invalid payload ------------------------------------------------>

        $input = compile(WebsocketFrameType::Pong, true, true, "0");
        $return[] = [$input, null, true, "TCP connection closed unexpectedly", WebsocketCloseCode::ABNORMAL_CLOSE];

        // x -------------------------------------------------------------------------------------->

        return $return;
    }
}
