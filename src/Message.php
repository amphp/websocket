<?php

namespace Amp\Websocket;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\Payload;

/**
 * This class allows streamed and buffered access to the websocket message by extending Payload.
 */
final class Message extends Payload
{
    /** @var bool */
    private $binary;

    public function __construct(InputStream $stream, bool $binary)
    {
        parent::__construct($stream);
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
}
