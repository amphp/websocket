<?php declare(strict_types=1);

namespace Amp\Websocket;

final class WebsocketClosedException extends WebsocketException
{
    /**
     * @param int $code Websocket close code. See {@see WebsocketCloseCode} for protocol-defined close codes.
     * @param string $reason Close reason if provided.
     */
    public function __construct(
        string $message,
        int $code,
        private readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf(
            '%s; Code %s (%s); Reason: "%s"',
            $message,
            $code,
            WebsocketCloseCode::getName($code) ?? 'Unknown code',
            $reason,
        ), $code, $previous);
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Alias of {@see self::getCode()} with stricter return type.
     *
     * @return int See {@see WebsocketCloseCode} for protocol-defined close codes. Note that close codes are
     *      not limited to those defined by the protocol.
     */
    public function getCloseCode(): int
    {
        $code = $this->code;
        \assert(\is_int($code)); // For Psalm.

        return $code;
    }

    /**
     * @return non-empty-string|null {@see WebsocketCloseCode} constant name corresponding to the given code or
     *      null if the code is undefined.
     */
    public function getCloseCodeName(): ?string
    {
        return WebsocketCloseCode::getName($this->getCloseCode());
    }
}
