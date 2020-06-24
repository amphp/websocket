<?php

namespace Amp\Websocket;

use Amp\Struct;

final class ClientMetadata
{
    use Struct;

    /** @var int Next sequential client ID. */
    private static $nextId = 1;

    /** @var int */
    public $id;

    /** @var bool */
    public $closedByPeer = false;

    /** @var int|null */
    public $closeCode;

    /** @var string|null */
    public $closeReason;

    /** @var int */
    public $connectedAt = 0;

    /** @var int */
    public $closedAt = 0;

    /** @var int */
    public $lastReadAt = 0;

    /** @var int */
    public $lastSentAt = 0;

    /** @var int */
    public $lastDataReadAt = 0;

    /** @var int */
    public $lastDataSentAt = 0;

    /** @var int */
    public $lastHeartbeatAt = 0;

    /** @var int */
    public $bytesRead = 0;

    /** @var int */
    public $bytesSent = 0;

    /** @var int */
    public $framesRead = 0;

    /** @var int */
    public $framesSent = 0;

    /** @var int */
    public $messagesRead = 0;

    /** @var int */
    public $messagesSent = 0;

    /** @var int */
    public $pingCount = 0;

    /** @var int */
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
