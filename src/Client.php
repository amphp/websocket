<?php

namespace Amp\Websocket;

use Amp\ByteStream\ReadableStream;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;

interface Client
{
    /**
     * Receive a message from the remote Websocket endpoint.
     *
     * @return Message|null Returns message sent by the remote or null if the connection closes normally.
     *
     * @throws ClosedException Thrown if the connection is closed abnormally.
     */
    public function receive(): ?Message;

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
     * @throws ClosedException Thrown if sending to the client fails.
     */
    public function send(string $data): void;

    /**
     * Sends a binary message to the endpoint.
     *
     * @param string $data Payload to send.
     *
     * @throws ClosedException Thrown if sending to the client fails.
     */
    public function sendBinary(string $data): void;

    /**
     * Streams the given UTF-8 text stream to the endpoint. This method should be used only for large payloads such as
     * files. Use send() for smaller payloads.
     *
     * @param ReadableStream $stream
     *
     * @throws ClosedException Thrown if sending to the client fails.
     */
    public function stream(ReadableStream $stream): void;

    /**
     * Streams the given binary to the endpoint. This method should be used only for large payloads such as
     * files. Use sendBinary() for smaller payloads.
     *
     * @param ReadableStream $stream
     *
     * @throws ClosedException Thrown if sending to the client fails.
     */
    public function streamBinary(ReadableStream $stream): void;

    /**
     * Sends a ping to the endpoint.
     */
    public function ping(): void;

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
     * @return array Returns an array containing the close code at key 0 and the close reason at key 1.
     *               These may differ from those provided if the connection was closed prior.
     */
    public function close(int $code = Code::NORMAL_CLOSE, string $reason = ''): array;

    /**
     * Attaches a callback invoked when the client closes. The callback is passed this object as the first parameter,
     * the close code as the second parameter, and the close reason as the third parameter.
     *
     * @param \Closure(Client, int, string):void $closure
     */
    public function onClose(\Closure $closure): void;
}
