<?php

namespace Amp\Websocket;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\Payload;
use Amp\Promise;

/**
 * This class allows streamed and buffered access to the websocket message.
 */
final class Message implements InputStream
{
    /** @var Payload */
    private $stream;

    /** @var bool */
    private $binary;

    /**
     * Create a Message from a UTF-8 text stream.
     *
     * @param InputStream $stream UTF-8 text stream.
     *
     * @return self
     */
    public static function fromText(InputStream $stream): self
    {
        return new self($stream, false);
    }

    /**
     * Create a Message from a binary stream.
     *
     * @param InputStream $stream Binary stream.
     *
     * @return self
     */
    public static function fromBinary(InputStream $stream): self
    {
        return new self($stream, true);
    }

    private function __construct(InputStream $stream, bool $binary)
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

    public function read(): Promise
    {
        return $this->stream->read();
    }

    public function buffer(): Promise
    {
        return $this->stream->buffer();
    }
}
