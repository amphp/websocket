<?php

namespace Amp\Websocket\Test;

use Amp\ByteStream\PipelineStream;
use Amp\Deferred;
use Amp\Loop;
use Amp\PHPUnit\AsyncTestCase;
use Amp\PipelineSource;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\Socket;
use Amp\Websocket\ClosedException;
use Amp\Websocket\Code;
use Amp\Websocket\Opcode;
use Amp\Websocket\Options;
use Amp\Websocket\Rfc6455Client;
use PHPUnit\Framework\MockObject\MockObject;
use function Amp\async;
use function Amp\await;
use function Amp\delay;

class ClientTest extends AsyncTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->ignoreLoopWatchers();
    }

    /**
     * @return Socket|MockObject
     */
    protected function createSocket(): Socket
    {
        return $this->createMock(EncryptableSocket::class);
    }

    public function testGetId(): void
    {
        $socket = $this->createSocket();
        $options = Options::createServerDefault();

        $client1 = new Rfc6455Client($socket, $options, false);
        $client2 = new Rfc6455Client($socket, $options, false);

        $this->assertNotSame($client1->getId(), $client2->getId());

        $client1->close();
        $client2->close();
    }

    public function testClose(): void
    {
        $code = Code::PROTOCOL_ERROR;
        $reason = 'Close reason';

        $socket = $this->createSocket();
        $packet = compile(Opcode::CLOSE, false, true, \pack('n', $code) . $reason);
        $socket->expects($this->once())
            ->method('write')
            ->with($packet);

        $socket->expects($this->exactly(2))
            ->method('read')
            ->willReturnCallback(function () use ($code, $reason): ?string {
                static $initial = true;

                if ($initial) {
                    $initial = false;
                    delay(100);
                    return compile(Opcode::CLOSE, true, true, \pack('n', $code) . $reason);
                }

                return null;
            });

        $client = new Rfc6455Client($socket, Options::createServerDefault(), false);

        $promise = async(fn() => $client->receive()); // Promise should fail due to abnormal close.

        delay(0);

        [$reportedCode, $reportedReason] = $client->close($code, $reason);

        $this->assertFalse($client->isConnected());
        $this->assertFalse($client->isClosedByPeer());
        $this->assertSame($code, $client->getCloseCode());
        $this->assertSame($reason, $client->getCloseReason());
        $this->assertSame($code, $reportedCode);
        $this->assertSame($reason, $reportedReason);

        $this->expectException(ClosedException::class);
        $this->expectExceptionMessage('Connection closed');

        await($promise); // Should throw a ClosedException.
    }

    public function testCloseWithoutResponse(): void
    {
        $this->setMinimumRuntime(1000);
        $this->setTimeout(1100);

        $code = Code::NORMAL_CLOSE;
        $reason = 'Close reason';

        $socket = $this->createSocket();
        $packet = compile(Opcode::CLOSE, false, true, \pack('n', $code) . $reason);
        $socket->expects($this->once())
            ->method('write')
            ->with($packet);

        $socket->expects($this->once())
            ->method('read')
            ->willReturnCallback(function (): ?string {
                delay(1200);
                return null;
            });

        $client = new Rfc6455Client($socket, Options::createServerDefault()->withClosePeriod(1), false);

        $invoked = false;
        $client->onClose(function () use (&$invoked) {
            $invoked = true;
        });

        $promise = async(fn() => $client->receive()); // Promise should resolve with null on normal close.

        delay(0);

        $client->close($code, $reason);

        $this->assertFalse($client->isConnected());
        $this->assertFalse($client->isClosedByPeer());
        $this->assertSame($code, $client->getCloseCode());
        $this->assertSame($reason, $client->getCloseReason());

        delay(0);

        $this->assertTrue($invoked);

        $this->assertNull(await($promise));
    }


    public function testPing(): void
    {
        $socket = $this->createSocket();
        $packet = compile(Opcode::PING, false, true, '1');
        $socket->expects($this->once())
            ->method('write')
            ->with($packet);

        $client = new Rfc6455Client($socket, Options::createServerDefault(), false);

        $client->ping();

        $client->close();
    }

    public function testSend(): void
    {
        $socket = $this->createSocket();
        $packet = compile(Opcode::TEXT, false, true, 'data');
        $socket->expects($this->once())
            ->method('write')
            ->with($packet);

        $client = new Rfc6455Client($socket, Options::createServerDefault(), false);

        await($client->send('data'));

        $client->close();
    }

    public function testSendBinary(): void
    {
        $socket = $this->createSocket();
        $packet = compile(Opcode::BIN, false, true, 'data');
        $socket->expects($this->once())
            ->method('write')
            ->with($packet);

        $client = new Rfc6455Client($socket, Options::createServerDefault(), false);

        await($client->sendBinary('data'));

        $client->close();
    }

    public function testStream(): void
    {
        $socket = $this->createSocket();
        $packet = compile(Opcode::TEXT, false, true, 'chunk1chunk2chunk3');
        $socket->expects($this->once())
            ->method('write')
            ->with($packet);

        $client = new Rfc6455Client($socket, Options::createServerDefault(), false);

        $emitter = new PipelineSource;
        $emitter->emit('chunk1');
        $emitter->emit('chunk2');
        $emitter->emit('chunk3');
        $emitter->complete();

        $stream = new PipelineStream($emitter->pipe());

        await($client->stream($stream));

        $client->close();
    }

    public function testStreamMultipleChunks(): void
    {
        $packets = [
            compile(Opcode::TEXT, false, false, 'chunk1chunk2'),
            compile(Opcode::CONT, false, true, 'chunk3'),
        ];

        $socket = $this->createSocket();
        $socket->expects($this->exactly(2))
            ->method('write')
            ->withConsecutive(...\array_map(function (string $packet) {
                return [$packet];
            }, $packets));

        $client = new Rfc6455Client($socket, Options::createServerDefault()->withStreamThreshold(10), false);

        $emitter = new PipelineSource;
        $emitter->emit('chunk1');
        $emitter->emit('chunk2');
        $emitter->emit('chunk');
        $emitter->emit('3');
        $emitter->complete();

        $stream = new PipelineStream($emitter->pipe());

        await($client->stream($stream));

        $client->close();
    }

    public function testMultipleClose(): void
    {
        $this->setMinimumRuntime(1000);
        $this->setTimeout(1100);

        // Dummy watcher to keep loop running while waiting on timeout in Rfc6455Client::close().
        $watcher = Loop::delay(2000, function (): void {
            $this->fail('Dummy watcher should not be invoked');
        });

        $socket = $this->createSocket();

        $deferred = new Deferred;

        $socket->method('read')
            ->willReturnCallback(fn() => await($deferred->promise()));

        $socket->expects($this->once())
            ->method('write');

        $socket->expects($this->once())
            ->method('close')
            ->willReturnCallback(function () use ($deferred): void {
                $deferred->resolve();
            });

        $client = new Rfc6455Client($socket, Options::createServerDefault()->withClosePeriod(1), false);

        $client->onClose($this->createCallback(1));

        $promise1 = async(fn() => $client->close(Code::NORMAL_CLOSE, 'First close'));
        $promise2 = async(fn() => $client->close(Code::ABNORMAL_CLOSE, 'Second close'));

        try {
            [[$code1, $reason1], [$code2, $reason2]] = await([$promise1, $promise2]);
        } finally {
            Loop::cancel($watcher);
        }

        // First close code should be used, second is ignored.
        $this->assertSame(Code::NORMAL_CLOSE, $client->getCloseCode());
        $this->assertSame('First close', $client->getCloseReason());

        $this->assertSame(Code::NORMAL_CLOSE, $code1);
        $this->assertSame('First close', $reason1);

        $this->assertSame(Code::NORMAL_CLOSE, $code2);
        $this->assertSame('First close', $reason2);
    }
}
