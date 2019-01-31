<?php

namespace Amp\Http\Websocket;

interface Application
{
    /**
     * Invoked when data messages arrive from the client.
     *
     * @param Client  $client
     * @param Message $message A stream of data received from the client
     */
    public function onData(Client $client, Message $message);

    /**
     * Invoked when the close handshake completes.
     *
     * @param Client $client A unique (to the current process) identifier for this client
     * @param int    $code   The websocket code describing the close
     * @param string $reason The reason for the close (may be empty)
     */
    public function onClose(Client $client, int $code, string $reason);
}
