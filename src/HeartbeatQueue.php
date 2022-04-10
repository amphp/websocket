<?php

namespace Amp\Websocket;

interface HeartbeatQueue
{
    /**
     * Insert the given client into the heartbeat queue.
     */
    public function insert(WebsocketClient $client): void;

    /**
     * Update the heartbeat interval for the given client.
     */
    public function update(WebsocketClient $client): void;

    /**
     * Remove the given client from the heartbeat queue.
     */
    public function remove(WebsocketClient $client): void;
}
