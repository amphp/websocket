<?php declare(strict_types=1);

namespace Amp\Websocket\Parser;

use Amp\Websocket\Opcode;

interface WebsocketParser
{
    public function push(string $data): void;

    public function compile(string $data, Opcode $opcode, bool $isFinal): string;
}
