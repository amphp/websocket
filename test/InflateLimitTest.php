<?php

namespace Amp\Websocket\Test;

use Amp\Delayed;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Promise;
use Amp\Socket\Socket;
use Amp\Success;
use Amp\Websocket\ClosedException;
use Amp\Websocket\Code;
use Amp\Websocket\Message;
use Amp\Websocket\Opcode;
use Amp\Websocket\Options;
use Amp\Websocket\Rfc6455Client;
use Amp\Websocket\Rfc7692Compression;
use function Amp\call;

/**
 * permessage-deflate (RFC 7692): decompressed bytes must count toward the message size limit, and a deflate bomb
 * must be rejected with 1009 before its full output is allocated.
 *
 * @requires extension zlib
 */
class InflateLimitTest extends AsyncTestCase
{
    private const LIMIT = 2 ** 20; // 1 MB

    public function testDeflateBombIsRejectedWithoutInflatingInFull(): \Generator
    {
        // 64 MB of zeros compresses to roughly 64 KB, well under the 1 MB frame and message limits on the wire.
        $bomb = self::deflate(\str_repeat("\0", 2 ** 20), 64);
        self::assertLessThan(self::LIMIT, \strlen($bomb));

        $frame = compile(Opcode::BIN, false, true, $bomb, 0b100);
        unset($bomb);

        \gc_collect_cycles();
        $baseline = \memory_get_usage();
        if (\function_exists('memory_reset_peak_usage')) {
            \memory_reset_peak_usage();
        }

        $client = $this->createClient([$frame]);
        unset($frame);

        list($data, $messages) = yield $this->receiveAll($client);

        self::assertSame(Code::MESSAGE_TOO_LARGE, $client->getCloseCode());
        self::assertSame('', $data);
        self::assertSame(0, $messages);

        if (\function_exists('memory_reset_peak_usage')) {
            self::assertLessThan(8 * 2 ** 20, \memory_get_peak_usage() - $baseline);
        }
    }

    public function testDecompressedBytesAreCountedAcrossFragments(): \Generator
    {
        $deflate = \deflate_init(\ZLIB_ENCODING_RAW, ['window' => 15]);
        $first = \deflate_add($deflate, \str_repeat('a', 600 * 1024), \ZLIB_SYNC_FLUSH);
        $second = \substr(\deflate_add($deflate, \str_repeat('a', 600 * 1024), \ZLIB_SYNC_FLUSH), 0, -4);

        $client = $this->createClient([
            compile(Opcode::BIN, false, false, $first, 0b100),
            compile(Opcode::CONT, false, true, $second),
        ]);

        yield $this->receiveAll($client);

        self::assertSame(Code::MESSAGE_TOO_LARGE, $client->getCloseCode());
    }

    public function testMessageWithinLimitIsDecompressedUnchanged(): \Generator
    {
        $text = \str_repeat('héllo wörld, ', 40000); // ~560 KB of UTF-8 text, compresses to a few KB.

        $client = $this->createClient([compile(Opcode::TEXT, false, true, self::deflate($text), 0b100)]);

        list($data) = yield $this->receiveAll($client);

        self::assertSame($text, $data);
        self::assertSame(Code::NORMAL_CLOSE, $client->getCloseCode());
    }

    public function testFragmentedCompressedTextSplitInsideMultibyteCharacter(): \Generator
    {
        $text = \str_repeat('ü', 5000);
        $deflate = \deflate_init(\ZLIB_ENCODING_RAW, ['window' => 15]);
        $first = \deflate_add($deflate, \substr($text, 0, 4999), \ZLIB_SYNC_FLUSH); // Ends mid-character.
        $second = \substr(\deflate_add($deflate, \substr($text, 4999), \ZLIB_SYNC_FLUSH), 0, -4);

        $client = $this->createClient([
            compile(Opcode::TEXT, false, false, $first, 0b100),
            compile(Opcode::CONT, false, true, $second),
        ]);

        list($data) = yield $this->receiveAll($client);

        self::assertSame($text, $data);
        self::assertSame(Code::NORMAL_CLOSE, $client->getCloseCode());
    }

    /**
     * @param string[] $chunks Data returned from successive socket reads.
     */
    private function createClient(array $chunks): Rfc6455Client
    {
        $reads = \array_map(function (string $chunk): Promise {
            return new Success($chunk);
        }, $chunks);
        $reads[] = new Delayed(1, null);

        $socket = $this->createMock(Socket::class);
        $socket->method('read')
            ->willReturnOnConsecutiveCalls(...$reads);
        $socket->method('write')
            ->willReturnCallback(function (string $data) {
                return new Success(\strlen($data));
            });

        // Client side context and client (masked: true), so server frames arrive unmasked.
        $context = Rfc7692Compression::fromServerHeader('permessage-deflate');
        self::assertNotNull($context);

        $options = Options::createClientDefault()
            ->withValidateUtf8(true)
            ->withMessageSizeLimit(self::LIMIT)
            ->withFrameSizeLimit(self::LIMIT);

        return new Rfc6455Client($socket, $options, true, $context);
    }

    /**
     * @return Promise<array{string, int}> Concatenated message data and number of messages received.
     */
    private function receiveAll(Rfc6455Client $client): Promise
    {
        return call(function () use ($client) {
            $data = '';
            $messages = 0;

            try {
                while ($message = yield $client->receive()) {
                    \assert($message instanceof Message);
                    $messages++;
                    $data .= yield $message->buffer();
                    $client->close();
                }
            } catch (ClosedException $exception) {
                // Expected when the connection is closed due to an error.
            }

            return [$data, $messages];
        });
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
