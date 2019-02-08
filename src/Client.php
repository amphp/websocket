<?php

namespace Amp\Websocket;

use Amp\ByteStream\InputStream;
use Amp\Promise;

interface Client
{
    /**
     * Receive a message from the remote Websocket endpoint.
     *
     * @return Promise<Message|null> Resolves to message sent by the remote.
     *
     * @throws ClosedException Thrown if the connection is closed.
     */
    public function receive(): Promise;

    /**
     * @return int Unique integer identifier for the client.
     */
    public function getId(): int;

    /**
     * @return bool True if the client is still connected, false otherwise. Returns false as soon as the closing
     *     handshake is initiated by the server or client.
     */
    public function isConnected(): bool;

    /**
     * @return string The local IP address or unix socket path of the client.
     */
    public function getLocalAddress(): string;

    /**
     * @return int|null The local port or null for unix sockets.
     */
    public function getLocalPort(): ?int;

    /**
     * @return string The remote IP address or unix socket path of the client.
     */
    public function getRemoteAddress(): string;

    /**
     * @return int|null The remote port or null for unix sockets.
     */
    public function getRemotePort(): ?int;

    /**
     * @return bool `true` if the client is encrypted, `false` if plaintext.
     */
    public function isEncrypted(): bool;

    /**
     * If the client is encrypted, returns the array returned from stream_get_meta_data($this->socket)["crypto"].
     * Otherwise returns an empty array.
     *
     * @return array
     */
    public function getCryptoContext(): array;

    /**
     * @return int Number of pings sent that have not been answered.
     */
    public function getUnansweredPingCount(): int;

    /**
     * @return int Client close code (generally one of those listed in Code, though not necessarily).
     *
     * @throws \Error Thrown if the client has not closed.
     */
    public function getCloseCode(): int;

    /**
     * @return string Client close reason.
     *
     * @throws \Error Thrown if the client has not closed.
     */
    public function getCloseReason(): string;

    /**
     * @return bool True if the peer initiated the websocket close.
     *
     * @throws \Error Thrown if the client has not closed.
     */
    public function didPeerInitiateClose(): bool;

    /**
     * Sends a text message to the endpoint. All data sent with this method must be valid UTF-8. Use `sendBinary()` if
     * you want to send binary data.
     *
     * @param string $data Payload to send.
     *
     * @return Promise<int> Resolves with the number of bytes sent to the other endpoint.
     *
     * @throws ClosedException Thrown if sending to the client fails.
     */
    public function send(string $data): Promise;

    /**
     * Sends a binary message to the endpoint.
     *
     * @param string $data Payload to send.
     *
     * @return Promise<int> Resolves with the number of bytes sent to the other endpoint.
     *
     * @throws ClosedException Thrown if sending to the client fails.
     */
    public function sendBinary(string $data): Promise;

    /**
     * Streams the given UTF-8 text stream to the endpoint. This method should be used only for large payloads such as
     * files. Use send() for smaller payloads.
     *
     * Data will not be compressed using this method (since compressed messages require buffering the entire message
     * before compressing).
     *
     * @param InputStream $stream
     *
     * @return Promise
     *
     * @throws ClosedException Thrown if sending to the client fails.
     */
    public function stream(InputStream $stream): Promise;

    /**
     * Streams the given binary to the endpoint. This method should be used only for large payloads such as
     * files. Use sendBinary() for smaller payloads.
     *
     * Data will not be compressed using this method (since compressed messages require buffering the entire message
     * before compressing).
     *
     * @param InputStream $stream
     *
     * @return Promise
     *
     * @throws ClosedException Thrown if sending to the client fails.
     */
    public function streamBinary(InputStream $stream): Promise;

    /**
     * Sends a ping to the endpoint.
     *
     * @return Promise<int> Resolves with the number of bytes sent to the other endpoint.
     */
    public function ping(): Promise;

    /**
     * Returns connection metadata.
     *
     * ```
     * [
     *     'local_address' => string,
     *     'local_port' => int|null
     *     'remote_address' => string,
     *     'remote_port' => int|null,
     *     'is_encrypted' => bool,
     *     'bytes_read' => int,
     *     'bytes_sent' => int,
     *     'frames_read' => int,
     *     'frames_sent' => int,
     *     'messages_read' => int,
     *     'messages_sent' => int,
     *     'connected_at' => int,
     *     'closed_at' => int,
     *     'close_code' => int|null,
     *     'close_reason' => string|null,
     *     'peer_initiated_close' => bool,
     *     'last_read_at' => int,
     *     'last_sent_at' => int,
     *     'last_data_read_at' => int,
     *     'last_data_sent_at' => int,
     *     'compression_enabled' => bool,
     * ]
     * ```
     *
     * @return array Array in the format described above.
     */
    public function getInfo(): array;

    /**
     * Closes the client connection.
     *
     * @param int    $code
     * @param string $reason
     *
     * @return Promise<int> Resolves with the number of bytes sent to the other endpoint.
     */
    public function close(int $code = Code::NORMAL_CLOSE, string $reason = ''): Promise;
}
