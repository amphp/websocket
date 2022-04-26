<?php

namespace Amp\Websocket;

interface RateLimiter
{
    /**
     * Notify the rate limiter of bytes received by the client. The rate limiter may suspend if it wishes to
     * prevent processing data and receiving further data.
     *
     * @param positive-int $bytes
     */
    public function notifyBytesReceived(WebsocketClient $client, int $bytes): void;

    /**
     * Notify the rate limiter of frames received by the client. The rate limiter may suspend if it wishes to
     * prevent processing data and receiving further data.
     *
     * @param positive-int $frames
     */
    public function notifyFramesReceived(WebsocketClient $client, int $frames): void;
}
