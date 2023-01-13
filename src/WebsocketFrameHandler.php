<?php declare(strict_types=1);

namespace Amp\Websocket;

interface WebsocketFrameHandler
{
    /**
     * Invoked each time a frame is received by the parser.
     */
    public function handleFrame(Opcode $opcode, string $data, bool $isFinal): void;
}
