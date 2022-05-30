<?php

namespace Amp\Websocket;

use Amp\ByteStream\BufferException;
use Amp\ByteStream\Payload;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;

/**
 * This class allows streamed and buffered access to the websocket message.
 */
final class WebsocketMessage implements ReadableStream
{
    private readonly Payload $stream;

    private readonly bool $binary;

    /**
     * Create a Message from a UTF-8 text stream.
     *
     * @param ReadableStream|string $stream UTF-8 text stream or string.
     */
    public static function fromText(ReadableStream|string $stream): self
    {
        return new self($stream, false);
    }

    /**
     * Create a Message from a binary stream.
     *
     * @param ReadableStream|string $stream Binary stream or string.
     */
    public static function fromBinary(ReadableStream|string $stream): self
    {
        return new self($stream, true);
    }

    private function __construct(ReadableStream|string $stream, bool $binary)
    {
        $this->stream = new Payload($stream);
        $this->binary = $binary;
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
        try {
            return $this->stream->read($cancellation);
        } catch (StreamException $exception) {
            $previous = $exception->getPrevious();
            throw $previous instanceof ClosedException ? $previous : $exception;
        }
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
        try {
            return $this->stream->buffer($cancellation, $limit);
        } catch (StreamException $exception) {
            $previous = $exception->getPrevious();
            throw $previous instanceof ClosedException ? $previous : $exception;
        }
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
}
