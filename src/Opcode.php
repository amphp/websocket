<?php

namespace Amp\Websocket;

enum Opcode: int
{
    case Continuation = 0x00;
    case Text = 0x01;
    case Binary = 0x02;
    case Close = 0x08;
    case Ping = 0x09;
    case Pong = 0x0A;

    public function isControlFrame(): bool
    {
        return $this->value >= 0x08;
    }
}
