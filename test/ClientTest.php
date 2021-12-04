<?php

namespace Amp\Websocket\Test;

use Amp\ByteStream\PipelineStream;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline\Emitter;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\Socket;
use Amp\Websocket\ClosedException;
use Amp\Websocket\Code;
use Amp\Websocket\Opcode;
use Amp\Websocket\Options;
use Amp\Websocket\Rfc6455Client;
use PHPUnit\Framework\MockObject\MockObject;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\delay;

class ClientTest extends AsyncTestCase
{
    /**
     * @return Socket&MockObject
     */
    protected function createSocket(): Socket
    {
        return $this->createMock(EncryptableSocket::class);
    }

    public function testGetId(): void
    {
        $socket = $this->createSocket();
        $socket->method('write')->willReturn(Future::complete(null));
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
            ->with($packet)
            ->willReturn(Future::complete(null));

        $socket->expects($this->exactly(2))
            ->method('read')
            ->willReturnCallback(function () use ($code, $reason): ?string {
                static $initial = true;

                if ($initial) {
                    $initial = false;
                    delay(0.1);
                    return compile(Opcode::CLOSE, true, true, \pack('n', $code) . $reason);
                }

                return null;
            });

        $client = new Rfc6455Client($socket, Options::createServerDefault(), false);

        $future = async(fn() => $client->receive()); // Promise should fail due to abnormal close.

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

        $future->await(); // Should throw a ClosedException.
    }

    public function testCloseWithoutResponse(): void
    {
        $this->setMinimumRuntime(1);
        $this->setTimeout(1.1);

        $code = Code::NORMAL_CLOSE;
        $reason = 'Close reason';

        $socket = $this->createSocket();
        $packet = compile(Opcode::CLOSE, false, true, \pack('n', $code) . $reason);
        $socket->expects($this->once())
            ->method('write')
            ->with($packet)
            ->willReturn(Future::complete(null));

        $socket->expects($this->once())
            ->method('read')
            ->willReturnCallback(function (): ?string {
                delay(1.2);
                return null;
            });

        $client = new Rfc6455Client($socket, Options::createServerDefault()->withClosePeriod(1), false);

        $invoked = false;
        $client->onClose(function () use (&$invoked) {
            $invoked = true;
        });

        $future = async(fn() => $client->receive()); // Promise should resolve with null on normal close.

        delay(0);

        $client->close($code, $reason);

        $this->assertFalse($client->isConnected());
        $this->assertFalse($client->isClosedByPeer());
        $this->assertSame($code, $client->getCloseCode());
        $this->assertSame($reason, $client->getCloseReason());

        delay(0);

        $this->assertTrue($invoked);

        $this->assertNull($future->await());
    }


    public function testPing(): void
    {
        $socket = $this->createSocket();
        $packet = compile(Opcode::PING, false, true, '1');
        $socket->expects($this->atLeastOnce())
            ->method('write')
            ->withConsecutive([$packet])
            ->willReturn(Future::complete(null));

        $client = new Rfc6455Client($socket, Options::createServerDefault(), false);

        $client->ping();

        $client->close();
    }

    public function testSend(): void
    {
        $socket = $this->createSocket();
        $packet = compile(Opcode::TEXT, false, true, 'data');
        $socket->expects($this->atLeastOnce())
            ->method('write')
            ->withConsecutive([$packet])
            ->willReturn(Future::complete(null));

        $client = new Rfc6455Client($socket, Options::createServerDefault(), false);

        $client->send('data')->await();

        $client->close();
    }

    public function testSendSplit(): void
    {
        $packets = [
            compile(Opcode::TEXT, false, false, 'chunk1'),
            compile(Opcode::CONT, false, false, 'chunk2'),
            compile(Opcode::CONT, false, false, 'chunk3'),
            compile(Opcode::CONT, false, true, 'end'),
        ];

        $socket = $this->createSocket();
        $socket->expects($this->atLeast(\count($packets)))
            ->method('write')
            ->withConsecutive(...\array_map(function (string $packet) {
                return [$packet];
            }, $packets))
            ->willReturn(Future::complete(null));

        $client = new Rfc6455Client($socket, Options::createServerDefault()->withFrameSplitThreshold(6), false);

        $client->send('chunk1chunk2chunk3end');
    }

    public function testSendBinary(): void
    {
        $socket = $this->createSocket();
        $packet = compile(Opcode::BIN, false, true, 'data');
        $socket->expects($this->atLeastOnce())
            ->method('write')
            ->withConsecutive([$packet])
            ->willReturn(Future::complete(null));

        $client = new Rfc6455Client($socket, Options::createServerDefault(), false);

        $client->sendBinary('data')->await();

        $client->close();
    }

    public function testStream(): void
    {
        $socket = $this->createSocket();
        $packet = compile(Opcode::TEXT, false, true, 'chunk1chunk2chunk3');
        $socket->expects($this->atLeastOnce())
            ->method('write')
            ->withConsecutive([$packet])
            ->willReturn(Future::complete(null));

        $client = new Rfc6455Client($socket, Options::createServerDefault(), false);

        $emitter = new Emitter();
        $emitter->emit('chunk1');
        $emitter->emit('chunk2');
        $emitter->emit('chunk3');
        $emitter->complete();

        $stream = new PipelineStream($emitter->asPipeline());

        $client->stream($stream)->await();

        $client->close();
    }

    public function testStreamMultipleChunks(): void
    {
        $packets = [
            compile(Opcode::TEXT, false, false, 'chunk1chunk2'),
            compile(Opcode::CONT, false, true, 'chunk3'),
        ];

        $socket = $this->createSocket();
        $socket->expects($this->atLeast(\count($packets)))
            ->method('write')
            ->withConsecutive(...\array_map(function (string $packet) {
                return [$packet];
            }, $packets))
            ->willReturn(Future::complete(null));

        $client = new Rfc6455Client($socket, Options::createServerDefault()->withStreamThreshold(10), false);

        $emitter = new Emitter;
        $emitter->emit('chunk1');
        $emitter->emit('chunk2');
        $emitter->emit('chunk');
        $emitter->emit('3');
        $emitter->complete();

        $stream = new PipelineStream($emitter->asPipeline());

        $client->stream($stream)->await();
    }

    public function testMultipleClose(): void
    {
        $this->setMinimumRuntime(1);
        $this->setTimeout(1.1);

        // Dummy watcher to keep loop running while waiting on timeout in Rfc6455Client::close().
        $watcher = EventLoop::delay(2, function (): void {
            $this->fail('Dummy watcher should not be invoked');
        });

        $socket = $this->createSocket();

        $deferred = new DeferredFuture;

        $socket->method('read')
            ->willReturnCallback(fn() => $deferred->getFuture()->await());

        $socket->expects($this->once())
            ->method('write')
            ->willReturn(Future::complete(null));

        $socket->expects($this->once())
            ->method('close')
            ->willReturnCallback(function () use ($deferred): void {
                $deferred->complete(null);
            });

        $client = new Rfc6455Client($socket, Options::createServerDefault()->withClosePeriod(1), false);

        $client->onClose($this->createCallback(1));

        $future1 = async(fn() => $client->close(Code::NORMAL_CLOSE, 'First close'));
        $future2 = async(fn() => $client->close(Code::ABNORMAL_CLOSE, 'Second close'));

        try {
            [[$code1, $reason1], [$code2, $reason2]] = Future\all([$future1, $future2]);
        } finally {
            EventLoop::cancel($watcher);
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
