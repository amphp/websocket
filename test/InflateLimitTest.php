<?php declare(strict_types=1);

namespace Amp\Websocket;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Websocket\Compression\Rfc7692Compression;
use Amp\Websocket\Parser\Rfc6455Parser;
use Amp\Websocket\Parser\WebsocketFrameHandler;
use Amp\Websocket\Parser\WebsocketFrameType;
use Amp\Websocket\Parser\WebsocketParserException;

/**
 * permessage-deflate (RFC 7692): decompressed bytes must count toward the message size limit, and a deflate bomb
 * must be rejected with 1009 before its full output is allocated.
 *
 * @requires extension zlib
 */
class InflateLimitTest extends AsyncTestCase
{
    private const LIMIT = 2 ** 20; // 1 MB

    public function testDeflateBombIsRejectedWithoutInflatingInFull(): void
    {
        // 64 MB of zeros compresses to roughly 64 KB, well under the 1 MB frame and message limits on the wire.
        $bomb = self::deflate(\str_repeat("\0", 2 ** 20), 64);
        self::assertLessThan(self::LIMIT, \strlen($bomb));

        [$parser, $handler] = $this->createParser();
        $frame = compile(
            WebsocketFrameType::Binary,
            masked: false,
            isFinal: true,
            data: $bomb,
            rsv: Rfc7692Compression::RSV,
        );
        unset($bomb);

        \gc_collect_cycles();
        $baseline = \memory_get_usage();
        if (\function_exists('memory_reset_peak_usage')) {
            \memory_reset_peak_usage();
        }

        try {
            $parser->push($frame);
            self::fail('Expected the deflate bomb to be rejected');
        } catch (WebsocketParserException $exception) {
            self::assertSame(WebsocketCloseCode::MESSAGE_TOO_LARGE, $exception->getCode());
        }

        self::assertSame(0, $handler->frames);

        if (\function_exists('memory_reset_peak_usage')) {
            self::assertLessThan(8 * 2 ** 20, \memory_get_peak_usage() - $baseline);
        }
    }

    public function testDecompressedBytesAreCountedAcrossFragments(): void
    {
        $deflate = \deflate_init(\ZLIB_ENCODING_RAW, ['window' => 15]);
        $first = \deflate_add($deflate, \str_repeat('a', 600 * 1024), \ZLIB_SYNC_FLUSH);
        $second = \substr(\deflate_add($deflate, \str_repeat('a', 600 * 1024), \ZLIB_SYNC_FLUSH), 0, -4);

        [$parser] = $this->createParser();
        $parser->push(
            compile(
                WebsocketFrameType::Binary,
                masked: false,
                isFinal: false,
                data: $first,
                rsv: Rfc7692Compression::RSV,
            ),
        );

        $this->expectException(WebsocketParserException::class);
        $this->expectExceptionCode(WebsocketCloseCode::MESSAGE_TOO_LARGE);

        $parser->push(compile(WebsocketFrameType::Continuation, masked: false, isFinal: true, data: $second));
    }

    public function testMessageWithinLimitIsDecompressedUnchanged(): void
    {
        $text = \str_repeat('héllo wörld, ', 40_000); // ~560 KB of UTF-8 text, compresses to a few KB.

        [$parser, $handler] = $this->createParser();
        $parser->push(
            compile(
                WebsocketFrameType::Text,
                masked: false,
                isFinal: true,
                data: self::deflate($text),
                rsv: Rfc7692Compression::RSV,
            ),
        );

        self::assertSame($text, $handler->data);
    }

    public function testFragmentedCompressedTextSplitInsideMultibyteCharacter(): void
    {
        $text = \str_repeat('ü', 5000);
        $deflate = \deflate_init(\ZLIB_ENCODING_RAW, ['window' => 15]);
        $first = \deflate_add($deflate, \substr($text, 0, 4999), \ZLIB_SYNC_FLUSH); // Ends mid-character.
        $second = \substr(\deflate_add($deflate, \substr($text, 4999), \ZLIB_SYNC_FLUSH), 0, -4);

        [$parser, $handler] = $this->createParser();
        $parser->push(
            compile(
                WebsocketFrameType::Text,
                masked: false,
                isFinal: false,
                data: $first,
                rsv: Rfc7692Compression::RSV,
            ),
        );
        $parser->push(compile(WebsocketFrameType::Continuation, masked: false, isFinal: true, data: $second));

        self::assertSame($text, $handler->data);
    }

    /**
     * @return array{Rfc6455Parser, object{data: string, frames: int}}
     */
    private function createParser(): array
    {
        $handler = new class implements WebsocketFrameHandler {
            public string $data = '';
            public int $frames = 0;

            public function handleFrame(WebsocketFrameType $frameType, string $data, bool $isFinal): void
            {
                $this->data .= $data;
                $this->frames++;
            }
        };

        // Client side context and parser (masked: true), so server frames arrive unmasked.
        $context = Rfc7692Compression::fromServerHeader('permessage-deflate');
        self::assertNotNull($context);

        $parser = new Rfc6455Parser(
            $handler,
            masked: true,
            compressionContext: $context,
            messageSizeLimit: self::LIMIT,
            frameSizeLimit: self::LIMIT,
        );

        return [$parser, $handler];
    }

    private static function deflate(string $part, int $repeat = 1): string
    {
        $deflate = \deflate_init(\ZLIB_ENCODING_RAW, ['window' => 15]);
        $out = '';

        // deflate_add() ignores a flush on empty input, so the last part carries the sync flush.
        for ($i = 1; $i <= $repeat; $i++) {
            $out .= \deflate_add($deflate, $part, $i === $repeat ? \ZLIB_SYNC_FLUSH : \ZLIB_NO_FLUSH);
        }

        return \substr($out, 0, -4); // Strip the 00 00 ff ff tail, as RFC 7692 senders do.
    }
}
