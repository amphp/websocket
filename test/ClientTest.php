<?php

namespace Amp\Websocket\Test;

use Amp\ByteStream\IteratorStream;
use Amp\Deferred;
use Amp\Delayed;
use Amp\Emitter;
use Amp\Loop;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Promise;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\Socket;
use Amp\Success;
use Amp\Websocket\ClosedException;
use Amp\Websocket\Code;
use Amp\Websocket\Opcode;
use Amp\Websocket\Options;
use Amp\Websocket\Rfc6455Client;
use PHPUnit\Framework\MockObject\MockObject;

class ClientTest extends AsyncTestCase
{
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
    }

    public function testClose(): \Generator
    {
        $code = Code::PROTOCOL_ERROR;
        $reason = 'Close reason';

        $socket = $this->createSocket();
        $packet = compile(Opcode::CLOSE, false, true, \pack('n', $code) . $reason);
        $socket->expects($this->once())
            ->method('write')
            ->with($packet)
            ->willReturn(new Success(\strlen($packet)));

        $socket->expects($this->exactly(2))
            ->method('read')
            ->willReturnOnConsecutiveCalls(
                new Delayed(0, compile(Opcode::CLOSE, true, true, \pack('n', $code) . $reason)),
                new Success
            );

        $client = new Rfc6455Client($socket, Options::createServerDefault(), false);

        $promise = $client->receive(); // Promise should fail due to abnormal close.

        [$reportedCode, $reportedReason] = yield $client->close($code, $reason);

        $this->assertFalse($client->isConnected());
        $this->assertFalse($client->isClosedByPeer());
        $this->assertSame($code, $client->getCloseCode());
        $this->assertSame($reason, $client->getCloseReason());
        $this->assertSame($code, $reportedCode);
        $this->assertSame($reason, $reportedReason);

        $this->expectException(ClosedException::class);
        $this->expectExceptionMessage('Connection closed');

        yield $promise; // Should throw a ClosedException.
    }

    public function testCloseWithoutResponse(): \Generator
    {
        $code = Code::NORMAL_CLOSE;
        $reason = 'Close reason';

        $socket = $this->createSocket();
        $packet = compile(Opcode::CLOSE, false, true, \pack('n', $code) . $reason);
        $socket->expects($this->once())
            ->method('write')
            ->with($packet)
            ->willReturn(new Success(\strlen($packet)));

        $socket->expects($this->once())
            ->method('read')
            ->willReturn(new Delayed(1200));

        $client = new Rfc6455Client($socket, Options::createServerDefault()->withClosePeriod(1), false);

        $invoked = false;
        $client->onClose(function () use (&$invoked) {
            $invoked = true;
        });

        Loop::delay(1100, function () use (&$invoked) {
            if (!$invoked) {
                $this->fail("Close timeout period not enforced");
            }
        });

        $promise = $client->receive(); // Promise should resolve with null on normal close.

        yield $client->close($code, $reason);

        $this->assertFalse($client->isConnected());
        $this->assertFalse($client->isClosedByPeer());
        $this->assertSame($code, $client->getCloseCode());
        $this->assertSame($reason, $client->getCloseReason());

        $this->assertTrue($invoked);

        $this->assertNull(yield $promise);
    }


    public function testPing(): \Generator
    {
        $socket = $this->createSocket();
        $packet = compile(Opcode::PING, false, true, '1');
        $socket->expects($this->once())
            ->method('write')
            ->with($packet)
            ->willReturn(new Success(\strlen($packet)));

        $client = new Rfc6455Client($socket, Options::createServerDefault(), false);

        yield $client->ping();
    }

    public function testSend(): \Generator
    {
        $socket = $this->createSocket();
        $packet = compile(Opcode::TEXT, false, true, 'data');
        $socket->expects($this->once())
            ->method('write')
            ->with($packet)
            ->willReturn(new Success(\strlen($packet)));

        $client = new Rfc6455Client($socket, Options::createServerDefault(), false);

        yield $client->send('data');
    }

    public function testSendSplit(): \Generator
    {
        $packets = [
            compile(Opcode::TEXT, false, false, 'chunk1'),
            compile(Opcode::CONT, false, false, 'chunk2'),
            compile(Opcode::CONT, false, false, 'chunk3'),
            compile(Opcode::CONT, false, true, 'end'),
        ];

        $socket = $this->createSocket();
        $socket->expects($this->exactly(\count($packets)))
            ->method('write')
            ->withConsecutive(...\array_map(function (string $packet) {
                return [$packet];
            }, $packets))
            ->willReturnOnConsecutiveCalls(
                ...\array_map(function (string $packet): Promise {
                    return new Success(\strlen($packet));
                }, $packets)
            );

        $client = new Rfc6455Client($socket, Options::createServerDefault()->withFrameSplitThreshold(6), false);

        yield $client->send('chunk1chunk2chunk3end');
    }

    public function testSendBinary(): \Generator
    {
        $socket = $this->createSocket();
        $packet = compile(Opcode::BIN, false, true, 'data');
        $socket->expects($this->once())
            ->method('write')
            ->with($packet)
            ->willReturn(new Success(\strlen($packet)));

        $client = new Rfc6455Client($socket, Options::createServerDefault(), false);

        yield $client->sendBinary('data');
    }

    public function testStream(): \Generator
    {
        $socket = $this->createSocket();
        $packet = compile(Opcode::TEXT, false, true, 'chunk1chunk2chunk3');
        $socket->expects($this->once())
            ->method('write')
            ->with($packet)
            ->willReturn(new Success(\strlen($packet)));

        $client = new Rfc6455Client($socket, Options::createServerDefault(), false);

        $emitter = new Emitter;
        $emitter->emit('chunk1');
        $emitter->emit('chunk2');
        $emitter->emit('chunk3');
        $emitter->complete();

        $stream = new IteratorStream($emitter->iterate());

        yield $client->stream($stream);
    }

    public function testStreamMultipleChunks(): \Generator
    {
        $packets = [
            compile(Opcode::TEXT, false, false, 'chunk1chunk2'),
            compile(Opcode::CONT, false, true, 'chunk3'),
        ];

        $socket = $this->createSocket();
        $socket->expects($this->exactly(\count($packets)))
            ->method('write')
            ->withConsecutive(...\array_map(function (string $packet) {
                return [$packet];
            }, $packets))
            ->willReturnOnConsecutiveCalls(
                ...\array_map(function (string $packet): Promise {
                    return new Success(\strlen($packet));
                }, $packets)
            );

        $client = new Rfc6455Client($socket, Options::createServerDefault()->withStreamThreshold(10), false);

        $emitter = new Emitter;
        $emitter->emit('chunk1');
        $emitter->emit('chunk2');
        $emitter->emit('chunk');
        $emitter->emit('3');
        $emitter->complete();

        $stream = new IteratorStream($emitter->iterate());

        yield $client->stream($stream);
    }

    public function testMultipleClose(): \Generator
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
            ->willReturn($deferred->promise());

        $socket->expects($this->once())
            ->method('write')
            ->willReturn(new Success);

        $socket->expects($this->once())
            ->method('close')
            ->willReturnCallback(function () use ($deferred): void {
                $deferred->resolve();
            });

        $client = new Rfc6455Client($socket, Options::createServerDefault()->withClosePeriod(1), false);

        $client->onClose($this->createCallback(1));

        $promise1 = $client->close(Code::NORMAL_CLOSE, 'First close');
        $promise2 = $client->close(Code::ABNORMAL_CLOSE, 'Second close');

        try {
            [[$code1, $reason1], [$code2, $reason2]] = yield [$promise1, $promise2];
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
