<?php declare(strict_types=1);

namespace Amp\Websocket\Parser;

final class ParserException extends \Exception
{
    public function __construct(int $code, string $message)
    {
        parent::__construct($message, $code);
    }
}
