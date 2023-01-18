<?php declare(strict_types=1);

namespace Amp\Websocket\Parser;

use Amp\Websocket\Opcode;

interface WebsocketParser
{
    /**
     * Parse websocket frames from peer data, invoking {@see WebsocketFrameHandler::handleFrame()} for each frame.
     *
     * @throws ParserException
     */
    public function push(string $data): void;

    /**
     * Cancel parsing and free any associated resources.
     */
    public function cancel(): void;

    /**
     * Provides stateful compilation of websocket frames. Continuation frames must be proceeded by an initial text
     * or binary frame. Another text or binary frame cannot be sent until a final continuation frame is sent.
     * Control frames may be interleaved.
     */
    public function compileFrame(Opcode $opcode, string $data, bool $isFinal): string;
}
