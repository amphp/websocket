<?php

namespace Amp\Websocket;

final class Opcode
{
    public const CONT = 0x00;
    public const TEXT = 0x01;
    public const BIN = 0x02;
    public const CLOSE = 0x08;
    public const PING = 0x09;
    public const PONG = 0x0A;

    /**
     * @codeCoverageIgnore Class cannot be instigated.
     */
    private function __construct()
    {
        // forbid instances
    }
}
