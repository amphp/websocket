<?php declare(strict_types=1);

namespace Amp\Websocket\Internal;

/** @internal */
final class WebsocketClientMetadata
{
    /** @var int Next sequential client ID. */
    private static int $nextId = 0;

    public readonly int $id;

    public bool $closedByPeer = false;

    public ?int $closeCode = null;

    public ?string $closeReason = null;

    public readonly int $connectedAt;

    public int $closedAt = 0;

    public int $lastReadAt = 0;

    public int $lastSentAt = 0;

    public int $lastDataReadAt = 0;

    public int $lastDataSentAt = 0;

    public int $lastHeartbeatAt = 0;

    public int $bytesRead = 0;

    public int $bytesSent = 0;

    public int $framesRead = 0;

    public int $framesSent = 0;

    public int $messagesRead = 0;

    public int $messagesSent = 0;

    public int $pingCount = 0;

    public int $pongCount = 0;

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
