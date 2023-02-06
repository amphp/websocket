<?php declare(strict_types=1);

namespace Amp\Websocket;

use Amp\ByteStream\BufferException;
use Amp\ByteStream\Payload;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/**
 * This class allows streamed and buffered access to the websocket message.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class WebsocketMessage implements ReadableStream, \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly Payload $stream;

    /**
     * Create a WebsocketMessage from a UTF-8 text stream.
     *
     * @param ReadableStream|string $stream UTF-8 text stream or string.
     */
    public static function fromText(ReadableStream|string $stream): self
    {
        return new self($stream, false);
    }

    /**
     * Create a WebsocketMessage from a binary stream.
     *
     * @param ReadableStream|string $stream Binary stream or string.
     */
    public static function fromBinary(ReadableStream|string $stream): self
    {
        return new self($stream, true);
    }

    private function __construct(ReadableStream|string $stream, private readonly bool $binary)
    {
        $this->stream = new Payload($stream);
    }

    /**
     * @return bool True if the message is UTF-8 text, false if it is binary.
     *
     * @see WebsocketMessage::isBinary()
     */
    public function isText(): bool
    {
        return !$this->binary;
    }

    /**
     * @return bool True if the message is binary, false if it is UTF-8 text.
     *
     * @see WebsocketMessage::isText()
     */
    public function isBinary(): bool
    {
        return $this->binary;
    }

    /**
     * @throws StreamException
     * @throws ClosedException
     */
    public function read(?Cancellation $cancellation = null): ?string
    {
        return $this->stream->read($cancellation);
    }

    /**
     * Buffer the entire message contents. Note that the given size limit may not be reached if a smaller message size
     * limit has been imposed by {@see Rfc6455Client::$messageSizeLimit}.
     *
     * @see Payload::buffer()
     *
     * @throws BufferException|StreamException
     * @throws ClosedException
     */
    public function buffer(?Cancellation $cancellation = null, int $limit = \PHP_INT_MAX): string
    {
        return $this->stream->buffer($cancellation, $limit);
    }

    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    /**
     * Indicates the remainder of the message is no longer needed and will be discarded.
     */
    public function close(): void
    {
        $this->stream->close();
    }

    public function isClosed(): bool
    {
        return $this->stream->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->stream->onClose($onClose);
    }

    public function getIterator(): \Traversable
    {
        return $this->stream->getIterator();
    }
}
