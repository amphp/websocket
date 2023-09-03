<?php declare(strict_types=1);

namespace Amp\Websocket\Internal;

/** @internal */
final class WebsocketClientMetadata
{
    /** @var int<0, max> Next sequential client ID. */
    private static int $nextId = 0;

    /** @var int<0, max> */
    public readonly int $id;

    public bool $closedByPeer = false;

    public ?int $closeCode = null;

    public ?string $closeReason = null;

    /** @var int<0, max> */
    public readonly int $connectedAt;

    /** @var int<0, max> */
    public int $closedAt = 0;

    /** @var int<0, max> */
    public int $lastReadAt = 0;

    /** @var int<0, max> */
    public int $lastSentAt = 0;

    /** @var int<0, max> */
    public int $lastDataReadAt = 0;

    /** @var int<0, max> */
    public int $lastDataSentAt = 0;

    /** @var int<0, max> */
    public int $lastHeartbeatAt = 0;

    /** @var int<0, max> */
    public int $bytesReceived = 0;

    /** @var int<0, max> */
    public int $bytesSent = 0;

    /** @var int<0, max> */
    public int $framesReceived = 0;

    /** @var int<0, max> */
    public int $framesSent = 0;

    /** @var int<0, max> */
    public int $messagesReceived = 0;

    /** @var int<0, max> */
    public int $messagesSent = 0;

    /** @var int<0, max> */
    public int $pingsReceived = 0;

    /** @var int<0, max> */
    public int $pingsSent = 0;

    /** @var int<0, max> */
    public int $pongsReceived = 0;

    /** @var int<0, max> */
    public int $pongsSent = 0;

    public function __construct(
        public readonly bool $compressionEnabled,
    ) {
        $this->id = self::$nextId++;

        $this->connectedAt = \time();
    }

    public function isClosed(): bool
    {
        return (bool) $this->closedAt;
    }
}
