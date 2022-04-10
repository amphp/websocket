<?php

namespace Amp\Websocket;

use Revolt\EventLoop\Suspension;

interface RateLimiter
{
    /**
     * Add the number of bytes to the current total for the given client.
     */
    public function addToByteCount(WebsocketClient $client, int $bytes): void;

    /**
     * Add the number of frames to the current total for the given client.
     */
    public function addToFrameCount(WebsocketClient $client, int $frames): void;

    /**
     * Get the {@see Suspension} for the given client if the rate limiter wishes to pause reading data on the client.
     */
    public function getSuspension(WebsocketClient $client): ?Suspension;
}
