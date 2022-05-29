<?php

namespace Amp\Websocket\Test;

use Amp\ByteStream\ReadableIterableStream;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline\Queue;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\Socket;
use Amp\Websocket\CloseCode;
use Amp\Websocket\ClosedException;
use Amp\Websocket\Opcode;
use Amp\Websocket\Rfc6455Client;
use PHPUnit\Framework\MockObject\MockObject;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\delay;

class WebsocketClientTest extends AsyncTestCase
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

        $client1 = new Rfc6455Client($socket, masked: false);
        $client2 = new Rfc6455Client($socket, masked: false);

        $this->assertNotSame($client1->getId(), $client2->getId());

        $client1->close();
        $client2->close();
    }

    public function testClose(): void
    {
        $code = CloseCode::PROTOCOL_ERROR;
        $reason = 'Close reason';

        $socket = $this->createSocket();
        $packet = compile(Opcode::Close, false, true, \pack('n', $code) . $reason);
        $socket->expects($this->once())
            ->method('write')
            ->with($packet);

        $socket->expects($this->exactly(2))
            ->method('read')
            ->willReturnCallback(function () use ($code, $reason): ?string {
                static $initial = true;

                if ($initial) {
                    $initial = false;
                    delay(0.1);
                    return compile(Opcode::Close, true, true, \pack('n', $code) . $reason);
                }

                return null;
            });

        $client = new Rfc6455Client($socket, masked: false);

        $future = async(fn () => $client->receive()); // Promise should fail due to abnormal close.

        delay(0);

        $client->close($code, $reason);

        $this->assertTrue($client->isClosed());
        $this->assertFalse($client->isClosedByPeer());
        $this->assertSame($code, $client->getCloseCode());
        $this->assertSame($reason, $client->getCloseReason());

        $this->expectException(ClosedException::class);
        $this->expectExceptionMessage('Connection closed');

        $future->await(); // Should throw a ClosedException.
    }

    public function testCloseWithoutResponse(): void
    {
        $this->setMinimumRuntime(1);
        $this->setTimeout(1.1);

        $code = CloseCode::NORMAL_CLOSE;
        $reason = 'Close reason';

        $socket = $this->createSocket();
        $packet = compile(Opcode::Close, false, true, \pack('n', $code) . $reason);
        $socket->expects($this->once())
            ->method('write')
            ->with($packet);

        $socket->expects($this->once())
            ->method('read')
            ->willReturnCallback(function (): ?string {
                delay(1.2);
                return null;
            });

        $client = new Rfc6455Client($socket, masked: false, closePeriod: 1);

        $invoked = false;
        $client->onClose(function () use (&$invoked) {
            $invoked = true;
        });

        $future = async(fn () => $client->receive()); // Promise should resolve with null on normal close.

        delay(0);

        $client->close($code, $reason);

        $this->assertTrue($client->isClosed());
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
        $packet = compile(Opcode::Ping, false, true, '1');
        $socket->expects($this->atLeastOnce())
            ->method('write')
            ->withConsecutive([$packet]);

        $client = new Rfc6455Client($socket, masked: false);

        $client->ping();

        $client->close();
    }

    public function testSend(): void
    {
        $socket = $this->createSocket();
        $packet = compile(Opcode::Text, false, true, 'data');
        $socket->expects($this->atLeastOnce())
            ->method('write')
            ->withConsecutive([$packet]);

        $client = new Rfc6455Client($socket, masked: false);

        $client->send('data');

        $client->close();
    }

    public function testSendSplit(): void
    {
        $packets = [
            compile(Opcode::Text, false, false, 'chunk1'),
            compile(Opcode::Continuation, false, false, 'chunk2'),
            compile(Opcode::Continuation, false, false, 'chunk3'),
            compile(Opcode::Continuation, false, true, 'end'),
        ];

        $socket = $this->createSocket();
        $socket->expects($this->atLeast(\count($packets)))
            ->method('write')
            ->withConsecutive(...\array_map(function (string $packet) {
                return [$packet];
            }, $packets));

        $client = new Rfc6455Client($socket, masked: false, frameSplitThreshold: 6);

        $client->send('chunk1chunk2chunk3end');
    }

    public function testSendBinary(): void
    {
        $socket = $this->createSocket();
        $packet = compile(Opcode::Binary, false, true, 'data');
        $socket->expects($this->atLeastOnce())
            ->method('write')
            ->withConsecutive([$packet]);

        $client = new Rfc6455Client($socket, masked: false);

        $client->sendBinary('data');

        $client->close();
    }

    public function testStream(): void
    {
        $socket = $this->createSocket();
        $packet = compile(Opcode::Text, false, true, 'chunk1chunk2chunk3');
        $socket->expects($this->atLeastOnce())
            ->method('write')
            ->withConsecutive([$packet]);

        $client = new Rfc6455Client($socket, masked: false);

        $emitter = new Queue();
        $emitter->pushAsync('chunk1');
        $emitter->pushAsync('chunk2');
        $emitter->pushAsync('chunk3');
        $emitter->complete();

        $stream = new ReadableIterableStream($emitter->pipe());

        $client->stream($stream);

        $client->close();
    }

    public function testStreamMultipleChunks(): void
    {
        $packets = [
            compile(Opcode::Text, false, false, 'chunk1chunk2'),
            compile(Opcode::Continuation, false, true, 'chunk3'),
        ];

        $socket = $this->createSocket();
        $socket->expects($this->atLeast(\count($packets)))
            ->method('write')
            ->withConsecutive(...\array_map(function (string $packet) {
                return [$packet];
            }, $packets));

        $client = new Rfc6455Client($socket, masked: false, streamThreshold: 10);

        $emitter = new Queue();
        $emitter->pushAsync('chunk1');
        $emitter->pushAsync('chunk2');
        $emitter->pushAsync('chunk');
        $emitter->pushAsync('3');
        $emitter->complete();

        $stream = new ReadableIterableStream($emitter->pipe());

        $client->stream($stream);
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
            ->willReturnCallback(fn () => $deferred->getFuture()->await());

        $socket->expects($this->once())
            ->method('write');

        $socket->expects($this->once())
            ->method('close')
            ->willReturnCallback(function () use ($deferred): void {
                $deferred->complete(null);
            });

        $client = new Rfc6455Client($socket, masked: false, closePeriod: 1);

        $client->onClose($this->createCallback(1));

        $future1 = async(fn () => $client->close(CloseCode::NORMAL_CLOSE, 'First close'));
        $future2 = async(fn () => $client->close(CloseCode::ABNORMAL_CLOSE, 'Second close'));

        try {
            Future\await([$future1, $future2]);
        } finally {
            EventLoop::cancel($watcher);
        }

        // First close code should be used, second is ignored.
        $this->assertSame(CloseCode::NORMAL_CLOSE, $client->getCloseCode());
        $this->assertSame('First close', $client->getCloseReason());
    }
}
