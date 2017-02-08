<?php

namespace Amp\Websocket;

use AsyncInterop\Promise;

class Message extends \Amp\Message {
    /** @var \AsyncInterop\Promise */
    private $binary;

    public function __construct(\Amp\Stream $stream, Promise $binary) {
        parent::__construct($stream);
        $this->binary = $binary;
    }

    /**
     * @return \AsyncInterop\Promise<bool> Returns a promise that resolves to true if the message is binary, false if
     *     it is UTF-8 text.
     */
    public function isBinary(): Promise {
        return $this->binary;
    }
}
