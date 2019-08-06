<?php

namespace Amp\Websocket\Test;

use Amp\ByteStream\IteratorStream;
use Amp\Delayed;
use Amp\Emitter;
use Amp\Loop;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Promise;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\Socket;
use Amp\Success;
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

        yield $client->close($code, $reason);

        $this->assertFalse($client->isConnected());
        $this->assertFalse($client->didPeerInitiateClose());
        $this->assertSame($code, $client->getCloseCode());
        $this->assertSame($reason, $client->getCloseReason());
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

        yield $client->close($code, $reason);

        $this->assertFalse($client->isConnected());
        $this->assertFalse($client->didPeerInitiateClose());
        $this->assertSame($code, $client->getCloseCode());
        $this->assertSame($reason, $client->getCloseReason());

        $this->assertTrue($invoked);
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
        $socket->expects($this->exactly(2))
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
}
