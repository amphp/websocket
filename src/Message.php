<?php

namespace Amp\Websocket;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\Payload;
use Amp\Cancellation;

/**
 * This class allows streamed and buffered access to the websocket message.
 */
final class Message implements ReadableStream
{
    private Payload $stream;

    private bool $binary;

    /**
     * Create a Message from a UTF-8 text stream.
     *
     * @param ReadableStream $stream UTF-8 text stream.
     *
     * @return self
     */
    public static function fromText(ReadableStream $stream): self
    {
        return new self($stream, false);
    }

    /**
     * Create a Message from a binary stream.
     *
     * @param ReadableStream $stream Binary stream.
     *
     * @return self
     */
    public static function fromBinary(ReadableStream $stream): self
    {
        return new self($stream, true);
    }

    private function __construct(ReadableStream $stream, bool $binary)
    {
        $this->stream = new Payload($stream);
        $this->binary = $binary;
    }

    /**
     * @return bool True if the message is UTF-8 text, false if it is binary.
     *
     * @see Message::isBinary()
     */
    public function isText(): bool
    {
        return !$this->binary;
    }

    /**
     * @return bool True if the message is binary, false if it is UTF-8 text.
     *
     * @see Message::isText()
     */
    public function isBinary(): bool
    {
        return $this->binary;
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        return $this->stream->read($cancellation);
    }

    public function buffer(): string
    {
        return $this->stream->buffer();
    }

	public function isReadable(): bool {
		return $this->stream->isReadable();
	}
}
