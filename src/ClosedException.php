<?php

namespace Amp\Websocket;

final class ClosedException extends \Exception
{
    /** @var string */
    private $reason;

    public function __construct(string $message, int $code, string $reason)
    {
        parent::__construct(\sprintf(
            '%s; Code %s (%s); Reason: "%s"',
            $message,
            $code,
            Code::getName($code) ?? 'Unknown code',
            $reason
        ), $code);

        $this->reason = $reason;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
