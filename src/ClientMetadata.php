<?php

namespace Amp\Websocket;

use Amp\Socket\Socket;
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

    /** @var string */
    public $localAddress;

    /** @var int|null */
    public $localPort;

    /** @var string */
    public $remoteAddress;

    /** @var int|null */
    public $remotePort;

    /** @var bool */
    public $isEncrypted;

    /** @var mixed[] Array from stream_get_meta_data($this->socket)["crypto"] or an empty array. */
    public $cryptoInfo = [];

    /**
     * @param Socket $socket
     * @param int    $time Current timestamp.
     * @param bool   $compressionEnabled
     */
    public function __construct(Socket $socket, int $time, bool $compressionEnabled)
    {
        $this->id = self::$nextId++;

        $resource = $socket->getResource();
        if ($resource !== null) {
            $this->cryptoInfo = \stream_get_meta_data($resource)["crypto"] ?? [];
        }
        $this->isEncrypted = !empty($this->cryptoInfo);
        $this->connectedAt = $time;
        $this->compressionEnabled = $compressionEnabled;

        $localName = (string) $socket->getLocalAddress();
        if ($portStartPos = \strrpos($localName, ":")) {
            $this->localAddress = \substr($localName, 0, $portStartPos);
            $this->localPort = (int) \substr($localName, $portStartPos + 1);
        } else {
            $this->localAddress = $localName;
        }

        $remoteName = (string) $socket->getRemoteAddress();
        if ($portStartPos = \strrpos($remoteName, ":")) {
            $this->remoteAddress = \substr($remoteName, 0, $portStartPos);
            $this->remotePort = (int) \substr($remoteName, $portStartPos + 1);
        } else {
            $this->remoteAddress = $localName;
        }
    }
}
