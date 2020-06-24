<?php

namespace Amp\Websocket;

use Amp\ByteStream\InputStream;
use Amp\Promise;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;

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
     * @return int Unique identifier for the client.
     */
    public function getId(): int;

    /**
     * @return bool True if the client is still connected, false otherwise. Returns false as soon as the closing
     *     handshake is initiated by the server or client.
     */
    public function isConnected(): bool;

    /**
     * @return SocketAddress Local socket address.
     */
    public function getLocalAddress(): SocketAddress;

    /**
     * @return SocketAddress Remote socket address.
     */
    public function getRemoteAddress(): SocketAddress;

    /**
     * @return TlsInfo|null TlsInfo object if connection is secure.
     */
    public function getTlsInfo(): ?TlsInfo;

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
    public function isClosedByPeer(): bool;

    /**
     * Sends a text message to the endpoint. All data sent with this method must be valid UTF-8. Use `sendBinary()` if
     * you want to send binary data.
     *
     * @param string $data Payload to send.
     *
     * @return Promise<void> Resolves once the message has been sent to the peer.
     *
     * @throws ClosedException Thrown if sending to the client fails.
     */
    public function send(string $data): Promise;

    /**
     * Sends a binary message to the endpoint.
     *
     * @param string $data Payload to send.
     *
     * @return Promise<void> Resolves once the message has been sent to the peer.
     *
     * @throws ClosedException Thrown if sending to the client fails.
     */
    public function sendBinary(string $data): Promise;

    /**
     * Streams the given UTF-8 text stream to the endpoint. This method should be used only for large payloads such as
     * files. Use send() for smaller payloads.
     *
     * @param InputStream $stream
     *
     * @return Promise<void> Resolves once the message has been sent to the peer.
     *
     * @throws ClosedException Thrown if sending to the client fails.
     */
    public function stream(InputStream $stream): Promise;

    /**
     * Streams the given binary to the endpoint. This method should be used only for large payloads such as
     * files. Use sendBinary() for smaller payloads.
     *
     * @param InputStream $stream
     *
     * @return Promise<void> Resolves once the message has been sent to the peer.
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
     * @return Options The options object associated with this client.
     */
    public function getOptions(): Options;

    /**
     * Returns connection metadata.
     *
     * @return ClientMetadata
     */
    public function getInfo(): ClientMetadata;

    /**
     * Closes the client connection.
     *
     * @param int    $code
     * @param string $reason
     *
     * @return Promise<array> Resolves with an array containing the close code at key 0 and the close reason at key 1.
     *                        These may differ from those provided if the connection was closed prior.
     */
    public function close(int $code = Code::NORMAL_CLOSE, string $reason = ''): Promise;

    /**
     * Attaches a callback invoked when the client closes. The callback is passed this object as the first parameter,
     * the close code as the second parameter, and the close reason as the third parameter.
     *
     * @param callable(Client $client, int $code, string $reason) $callback
     */
    public function onClose(callable $callback): void;
}
