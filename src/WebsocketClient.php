<?php declare(strict_types=1);

namespace Amp\Websocket;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\Closable;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;

interface WebsocketClient extends Closable
{
    /**
     * Receive a message from the remote Websocket endpoint.
     *
     * @param Cancellation|null $cancellation Cancel awaiting the next message. Note this does not close the
     * connection or discard the next message. A subsequent call to this method will still return the next message
     * received from the client.
     *
     * @return WebsocketMessage|null Returns the message sent by the remote or null if the connection closes.
     */
    public function receive(?Cancellation $cancellation = null): ?WebsocketMessage;

    /**
     * @return int Unique identifier for the client.
     */
    public function getId(): int;

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
     * @throws ClosedException Thrown if sending to the client fails.
     */
    public function stream(ReadableStream $stream): void;

    /**
     * Streams the given binary to the endpoint. This method should be used only for large payloads such as
     * files. Use sendBinary() for smaller payloads.
     *
     * @throws ClosedException Thrown if sending to the client fails.
     */
    public function streamBinary(ReadableStream $stream): void;

    /**
     * Sends a ping to the endpoint.
     */
    public function ping(): void;

    /**
     * Returns connection metadata.
     */
    public function getInfo(): WebsocketClientMetadata;

    /**
     * @return bool {@code false} if the client is still connected, {@code true} if the client has disconnected.
     * Returns {@code true} as soon as the closing handshake is initiated by the server or client.
     */
    public function isClosed(): bool;

    /**
     * Closes the client connection.
     */
    public function close(int $code = CloseCode::NORMAL_CLOSE, string $reason = ''): void;

    /**
     * Attaches a callback invoked when the client closes. The callback is passed the close code as the first
     * parameter and the close reason as the second parameter.
     *
     * @param \Closure(WebsocketClientMetadata):void $onClose
     */
    public function onClose(\Closure $onClose): void;
}
