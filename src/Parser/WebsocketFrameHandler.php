<?php declare(strict_types=1);

namespace Amp\Websocket\Parser;

use Amp\Websocket\Opcode;

interface WebsocketFrameHandler
{
    /**
     * Invoked each time a frame is received by the parser.
     */
    public function handleFrame(Opcode $opcode, string $data, bool $isFinal): void;
}
