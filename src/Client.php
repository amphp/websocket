<?php

namespace Amp\Http\Websocket;

use Amp\Promise;

interface Client
{
    /**
     * @return int Unique integer identifier for the client.
     */
    public function getId(): int;

    /**
     * @return int Number of pings sent that have not been answered.
     */
    public function getUnansweredPingCount(): int;

    /**
     * Sends a text message to the endpoint.
     *
     * @param string $data
     *
     * @return Promise
     */
    public function send(string $data): Promise;

    /**
     * Sends a binary message to the endpoint.
     *
     * @param string $data
     *
     * @return Promise
     */
    public function sendBinary(string $data): Promise;

    /**
     * Sends a ping to the endpoint.
     *
     * @return Promise
     */
    public function ping(): Promise;

    /**
     * @return array
     */
    public function getInfo(): array;

    /**
     * Closes the client connection.
     *
     * @param int    $code
     * @param string $reason
     *
     * @return Promise
     */
    public function close(int $code = Code::NORMAL_CLOSE, string $reason = ''): Promise;
}
