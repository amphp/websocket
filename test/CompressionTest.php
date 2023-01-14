<?php declare(strict_types=1);

namespace Amp\Websocket\Test;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Websocket\Compression\Rfc7692Compression;

class CompressionTest extends AsyncTestCase
{
    public function provideValidHeaders(): iterable
    {
        return [
            ['permessage-deflate'],
            ...\array_map(
                fn (string $header) => ['permessage-deflate; ' . $header],
                [
                    'server_no_context_takeover; client_no_context_takeover',
                    'client_max_window_bits=15; server_max_window_bits=9',
                    'server_no_context_takeover; server_max_window_bits=15',
                    'client_max_window_bits=9; client_no_context_takeover',
                ],
            ),
        ];
    }

    /**
     * @dataProvider provideValidHeaders
     */
    public function testFromServer(string $header): void
    {
        $compression = Rfc7692Compression::fromServerHeader($header);
        self::assertNotNull($compression);
        $this->testCompression($compression);
    }

    /**
     * @dataProvider provideValidHeaders
     */
    public function testFromClient(string $header): void
    {
        $compression = Rfc7692Compression::fromClientHeader($header, $out);
        self::assertNotNull($out);
        self::assertNotNull($compression);
        $this->testCompression($compression);
    }

    private function testCompression(Rfc7692Compression $compression): void
    {
        $data = \str_repeat('.', 10000);

        $compressed = $compression->compress($data, true);
        self::assertSame($data, $compression->decompress($compressed, true));
    }

    public function provideInvalidHeaders(): iterable
    {
        return [
            ['invalid-extension'],
            ...\array_map(
                fn (string $header) => ['permessage-deflate; ' . $header],
                [
                    'undefined_option', // undefined option
                    'client_max_window_bits=16', // window too high
                    'client_max_window_bits=8', // window too low
                    'server_max_window_bits', // no value
                    'client_max_window_bits', // no value
                ],
            )
        ];
    }

    /**
     * @dataProvider provideInvalidHeaders
     */
    public function testInvalidFromServer(string $header): void
    {
        self::assertNull(Rfc7692Compression::fromServerHeader($header));
    }

    /**
     * @dataProvider provideInvalidHeaders
     */
    public function testInvalidFromClient(string $header): void
    {
        self::assertNull(Rfc7692Compression::fromClientHeader($header, $out));
    }

    public function testInvalidPayload(): void
    {
        $compression = Rfc7692Compression::fromServerHeader('permessage-deflate');

        self::assertNotSame(0, $compression->getRsv());
        self::assertGreaterThan(0, $compression->getCompressionThreshold());

        self::assertNull($compression->decompress('invalid-payload', true));
        self::assertNull($compression->decompress('invalid-payload', false));
    }
}
