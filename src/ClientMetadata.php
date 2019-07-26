<?php

namespace Amp\Websocket;

use Amp\Struct;

final class ClientMetadata
{
    use Struct;

    /** @var string Next sequential client ID. */
    private static $nextId = 'a';

    /** @var string */
    public $id;

    /** @var bool */
    public $peerInitiatedClose = false;

    /** @var int|null */
    public $closeCode;

    /** @var string|null */
    public $closeReason;

    // Timestamps of when the event occurred.
    public $connectedAt = 0;
    public $closedAt = 0;
    public $lastReadAt = 0;
    public $lastSentAt = 0;
    public $lastDataReadAt = 0;
    public $lastDataSentAt = 0;
    public $lastHeartbeatAt = 0;

    // Simple counters.
    public $bytesRead = 0;
    public $bytesSent = 0;
    public $framesRead = 0;
    public $framesSent = 0;
    public $messagesRead = 0;
    public $messagesSent = 0;
    public $pingCount = 0;
    public $pongCount = 0;

    /** @var bool */
    public $compressionEnabled;

    /**
     * @param int    $time Current timestamp.
     * @param bool   $compressionEnabled
     */
    public function __construct(int $time, bool $compressionEnabled)
    {
        $this->id = self::$nextId++;

        $this->connectedAt = $time;
        $this->compressionEnabled = $compressionEnabled;
    }
}
