<?php declare(strict_types=1);

namespace Amp\Websocket\Test;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Pipeline\Queue;
use Amp\Socket\Socket;
use Amp\Socket\SocketException;
use Amp\Websocket\CloseCode;
use Amp\Websocket\ClosedException;
use Amp\Websocket\Opcode;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\Rfc6455Client;
use Amp\Websocket\WebsocketClientEventKey;
use PHPUnit\Framework\MockObject\MockObject;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\delay;

class WebsocketClientTest extends AsyncTestCase
{
    protected function createSocket(): Socket&MockObject
    {
        return $this->createMock(Socket::class);
    }

    private function createClient(
        Socket $socket,
        bool $masked = false,
        int $frameSplitThreshold = Rfc6455Client::DEFAULT_FRAME_SPLIT_THRESHOLD,
        int $closePeriod = Rfc6455Client::DEFAULT_CLOSE_PERIOD,
    ): Rfc6455Client {
        return new Rfc6455Client(
            socket: $socket,
            masked: $masked,
            parserFactory: new Rfc6455ParserFactory(),
            frameSplitThreshold: $frameSplitThreshold,
            closePeriod: $closePeriod,
        );
    }

    public function testGetId(): void
    {
        $socket = $this->createSocket();

        $client1 = $this->createClient($socket);
        $client2 = $this->createClient($socket);

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

        $client = $this->createClient($socket);

        $future = async(fn () => $client->receive());

        delay(0);

        $client->close($code, $reason);

        $this->assertTrue($client->isClosed());
        $this->assertFalse($client->isClosedByPeer());
        $this->assertSame($code, $client->getCloseCode());
        $this->assertFalse(CloseCode::isExpected($code));
        $this->assertSame($reason, $client->getCloseReason());
        $this->assertGreaterThan(0, $client->getLastEventTime(WebsocketClientEventKey::ClosedAt));

        self::assertNull($future->await());
    }

    public function testCloseWithoutResponse(): void
    {
        $this->setMinimumRuntime(1);
        $this->setTimeout(1.5);

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

        $client = $this->createClient($socket, closePeriod: 1);

        $future = async(fn () => $client->receive());

        delay(0);

        $client->close($code, $reason);

        $this->assertTrue($client->isClosed());
        $this->assertFalse($client->isClosedByPeer());
        $this->assertSame($code, $client->getCloseCode());
        $this->assertTrue(CloseCode::isExpected($code));
        $this->assertSame($reason, $client->getCloseReason());

        delay(0);

        $this->assertNull($future->await());
    }

    public function testPing(): void
    {
        $socket = $this->createSocket();
        $packet = compile(Opcode::Ping, false, true, '1');
        $socket->expects($this->atLeastOnce())
            ->method('write')
            ->withConsecutive([$packet]);

        $client = $this->createClient($socket);

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

        $client = $this->createClient($socket);

        $client->sendText('data');

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

        $client = $this->createClient($socket, frameSplitThreshold: 6);

        $client->sendText('chunk1chunk2chunk3end');
    }

    public function testSendBinary(): void
    {
        $socket = $this->createSocket();
        $packet = compile(Opcode::Binary, false, true, 'data');
        $socket->expects($this->atLeastOnce())
            ->method('write')
            ->withConsecutive([$packet]);

        $client = $this->createClient($socket);

        $client->sendBinary('data');

        $client->close();
    }

    public function testStream(): void
    {
        $packets = \array_map(fn (string $packet) => [$packet], [
            compile(Opcode::Text, false, false, 'chunk1'),
            compile(Opcode::Continuation, false, false, 'chunk2'),
            compile(Opcode::Continuation, false, true, 'chunk3'),
        ]);

        $socket = $this->createSocket();
        $socket->expects($this->atLeastOnce())
            ->method('write')
            ->withConsecutive(...$packets);

        $client = $this->createClient($socket);

        $emitter = new Queue();
        $emitter->pushAsync('chunk1');
        $emitter->pushAsync('chunk2');
        $emitter->pushAsync('chunk3');
        $emitter->complete();

        $stream = new ReadableIterableStream($emitter->pipe());

        $client->streamText($stream);

        $client->close();
    }

    public function testStreamMultipleChunks(): void
    {
        $packets = [
            compile(Opcode::Text, false, false, 'chunk1'),
            compile(Opcode::Continuation, false, false, 'chunk2'),
            compile(Opcode::Continuation, false, false, 'chunk'),
            compile(Opcode::Continuation, false, true, '3'),
        ];

        $socket = $this->createSocket();
        $socket->expects($this->atLeast(\count($packets)))
            ->method('write')
            ->withConsecutive(...\array_map(function (string $packet) {
                return [$packet];
            }, $packets));

        $client = $this->createClient($socket);

        $emitter = new Queue();
        $emitter->pushAsync('chunk1');
        $emitter->pushAsync('chunk2');
        $emitter->pushAsync('chunk');
        $emitter->pushAsync('3');
        $emitter->complete();

        $stream = new ReadableIterableStream($emitter->pipe());

        $client->streamText($stream);
    }

    public function testSendWithFailedSocket(): void
    {
        $socket = $this->createSocket();

        $socket->expects($this->atLeast(2))
            ->method('write')
            ->willReturnOnConsecutiveCalls(
                self::throwException(new SocketException('Mock exception')),
            );

        $client = $this->createClient($socket);

        $this->expectException(ClosedException::class);
        $this->expectExceptionMessage('Writing to the client failed');

        $client->sendText('data');
    }

    public function testStreamWithFailedSocket(): void
    {
        $socket = $this->createSocket();

        $socket->expects($this->atLeast(2))
            ->method('write')
            ->willReturnOnConsecutiveCalls(
                null,
                self::throwException(new SocketException('Mock exception')),
            );

        $client = $this->createClient($socket);

        $emitter = new Queue();
        $emitter->pushAsync('chunk1');
        $emitter->pushAsync('chunk2');
        $emitter->pushAsync('chunk');
        $emitter->pushAsync('3');
        $emitter->complete();

        $stream = new ReadableIterableStream($emitter->pipe());

        $this->expectException(ClosedException::class);
        $this->expectExceptionMessage('Writing to the client failed');

        $client->streamText($stream);
    }

    public function testStreamWithFailedStream(): void
    {
        $client = $this->createClient($this->createSocket());

        $exception = new \Exception('Test exception');

        $emitter = new Queue();
        $emitter->pushAsync('chunk');
        $emitter->error($exception);

        $this->expectExceptionObject($exception);

        $client->streamText(new ReadableIterableStream($emitter->pipe()));
    }

    public function testReceiveWhenSocketCloses(): void
    {
        $socket = $this->createSocket();

        $socket->method('read')
            ->willReturnOnConsecutiveCalls(
                compile(Opcode::Text, true, true, 'message'),
                self::throwException(new SocketException('Mock exception')),
            );

        $client = $this->createClient($socket);

        $message = $client->receive();
        self::assertSame('message', $message->buffer());

        self::assertNull($client->receive());

        self::assertSame(CloseCode::ABNORMAL_CLOSE, $client->getCloseCode());
        self::assertFalse(CloseCode::isExpected($client->getCloseCode()));
        self::assertStringContainsString('TCP connection closed', $client->getCloseReason());
    }

    public function testMultipleClose(): void
    {
        $this->setMinimumRuntime(1);
        $this->setTimeout(1.5);

        // Dummy watcher to keep loop running while waiting on timeout in Rfc6455Client::close().
        $watcher = EventLoop::delay(2, function (): void {
            $this->fail('Dummy watcher should not be invoked');
        });

        $socket = $this->createSocket();

        $deferred = new DeferredFuture;
        $future = $deferred->getFuture();

        $socket->method('onClose')
            ->willReturnCallback(fn (\Closure $onClose) => $future->finally($onClose));

        $socket->method('read')
            ->willReturnCallback(fn () => $future->await());

        $socket->expects($this->once())
            ->method('write');

        $socket->expects($this->once())
            ->method('close')
            ->willReturnCallback(fn () => $deferred->complete());

        $client = $this->createClient($socket, closePeriod: 1);

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

    public function testDisposedMessage(): void
    {
        $frames = [
            compile(Opcode::Text, true, false, 'chunk1'),
            compile(Opcode::Continuation, true, false, 'chunk2'),
            compile(Opcode::Continuation, true, true, 'chunk3'),
            compile(Opcode::Text, true, true, 'second'),
        ];

        $socket = $this->createSocket();

        $socket->method('read')
            ->willReturnOnConsecutiveCalls(...$frames);

        $client = $this->createClient($socket);

        $message = $client->receive();

        self::assertSame('chunk1', $message->read());
        unset($message);

        $message = $client->receive();

        self::assertSame('second', $message->read());
    }

    public function testReceiveIteration(): void
    {
        $frames = [
            compile(Opcode::Text, false, true, 'message0'),
            compile(Opcode::Text, false, true, 'message1'),
            compile(Opcode::Text, false, true, 'message2'),
            compile(Opcode::Close, false, true),
        ];

        $socket = $this->createSocket();

        $socket->method('read')
            ->willReturnOnConsecutiveCalls(...$frames);

        $client = $this->createClient($socket, true);

        foreach ($client as $key => $message) {
            self::assertSame('message' . $key, $message->buffer());
        }
    }

    public function testClientDestroyed(): void
    {
        $chunk = \str_repeat('*', Rfc6455Client::DEFAULT_FRAME_SPLIT_THRESHOLD);

        $future = async(fn () => delay(0.1));

        $socket = $this->createSocket();
        $socket->method('read')
            ->willReturnCallback(fn () => $future->await());

        $socket->expects(self::exactly(3))
            ->method('write')
            ->withConsecutive(...\array_map(fn (string $packet) => [$packet], [
                compile(Opcode::Text, false, false, $chunk),
                compile(Opcode::Continuation, false, true, $chunk),
                compile(Opcode::Close, false, true, \pack('n', CloseCode::GOING_AWAY)),
            ]));

        $socket->expects(self::once())
            ->method('close');

        $client = $this->createClient($socket);
        $client->streamText(new ReadableBuffer(\str_repeat($chunk, 2)));

        unset($client); // Should invoke destructor, send close frame, and close socket.

        $future->await();
    }
}
