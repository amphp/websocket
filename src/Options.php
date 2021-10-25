<?php

namespace Amp\Websocket;

final class Options
{
    /** @var int */
    private $streamThreshold = 32768; // 32KB

    /** @var int */
    private $frameSplitThreshold = 32768; // 32KB

    /** @var int */
    private $bytesPerSecondLimit = 1048576; // 1MB

    /** @var int */
    private $framesPerSecondLimit = 100;

    /** @var int */
    private $frameSizeLimit = 2097152; // 2MB

    /** @var int */
    private $messageSizeLimit = 10485760; // 10MB

    /** @var bool */
    private $textOnly = false;

    /** @var bool */
    private $validateUtf8 = true;

    /** @var int */
    private $closePeriod = 3;

    /** @var bool */
    private $compressionEnabled = false;

    /** @var bool */
    private $heartbeatEnabled = true;

    /** @var int */
    private $heartbeatPeriod = 10;

    /** @var int */
    private $queuedPingLimit = 3;

    /**
     * Creates an Options object with values as documented on the accessor methods.
     *
     * @return self
     */
    public static function createServerDefault(): self
    {
        return new self; // Initial parameter values already tuned for servers.
    }

    /**
     * Creates an Options object with values as documented on the accessor methods except for the changes below.
     *
     * Compression is enabled if the zlib extension is installed.
     * Bytes per second limit is set to PHP_INT_MAX (effectively removing the limit).
     * Frames per second limit is set to PHP_INI_MAX (effectively removing the limit).
     * Message size limit is set to 1 GB.
     * Frame size limit is set to 100 MB.
     *
     * @return self
     */
    public static function createClientDefault(): self
    {
        $options = new self;

        $options->bytesPerSecondLimit = \PHP_INT_MAX;
        $options->framesPerSecondLimit = \PHP_INT_MAX;
        $options->messageSizeLimit = 2 ** 30; // 1 GB
        $options->frameSizeLimit = 2 ** 20 * 100; // 100 MB

        if (\extension_loaded('zlib')) {
            $options->compressionEnabled = true;
        }

        return $options;
    }

    private function __construct()
    {
        // Private constructor to require use of named constructors.
    }

    /**
     * @return int Number of bytes that will be buffered when streaming a message
     *     body before sending a frame.
     */
    public function getStreamThreshold(): int
    {
        return $this->streamThreshold;
    }

    /**
     * @param int $streamThreshold Number of bytes that will be buffered when
     *     streaming a message body before sending a frame. Default is 32768 (32KB)
     *
     * @return self
     *
     * @throws \Error if the number is less than 1.
     */
    public function withStreamThreshold(int $streamThreshold): self
    {
        if ($streamThreshold < 1) {
            throw new \Error('$streamThreshold must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->streamThreshold = $streamThreshold;

        return $clone;
    }

    /**
     * @return int If a message exceeds this number of bytes, it is split into
     *     multiple frames, each no bigger than this value.
     */
    public function getFrameSplitThreshold(): int
    {
        return $this->frameSplitThreshold;
    }

    /**
     * @param int $frameSplitThreshold If a message exceeds this number of bytes,
     *     it is split into multiple frames, each no bigger than this value.
     *     Default is 32768 (32KB)
     *
     * @return self
     *
     * @throws \Error if number is less than 1.
     */
    public function withFrameSplitThreshold(int $frameSplitThreshold): self
    {
        if ($frameSplitThreshold < 1) {
            throw new \Error('$frameSplitThreshold must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->frameSplitThreshold = $frameSplitThreshold;

        return $clone;
    }

    /**
     * @return int Maximum frame size that can be received from the peer. If a
     *     larger frame is received, the connection is ended with a POLICY_VIOLATION.
     */
    public function getFrameSizeLimit(): int
    {
        return $this->frameSizeLimit;
    }

    /**
     * @param int $frameSizeLimit Maximum frame size that can be received from the peer.
     *     If a larger frame is received, the connection is ended with a POLICY_VIOLATION.
     *     Default is 2097152 (2MB)
     *
     * @return self
     *
     * @throws \Error if number is less than 1.
     */
    public function withFrameSizeLimit(int $frameSizeLimit): self
    {
        if ($frameSizeLimit < 1) {
            throw new \Error('$frameSizeLimit must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->frameSizeLimit = $frameSizeLimit;

        return $clone;
    }

    /**
     * @return int Maximum number of bytes the peer can send per second before being throttled.
     */
    public function getBytesPerSecondLimit(): int
    {
        return $this->bytesPerSecondLimit;
    }

    /**
     * @param int $bytesPerSecond Maximum number of bytes the peer can send per
     *     second before being throttled. Default is 1048576 (1MB)
     *
     * @return self
     *
     * @throws \Error if number is less than 1.
     */
    public function withBytesPerSecondLimit(int $bytesPerSecond): self
    {
        if ($bytesPerSecond < 1) {
            throw new \Error('$bytesPerSecond must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->bytesPerSecondLimit = $bytesPerSecond;

        return $clone;
    }

    /**
     * @return int Maximum number of frames the peer can send per second before being throttled.
     */
    public function getFramesPerSecondLimit(): int
    {
        return $this->framesPerSecondLimit;
    }

    /**
     * @param int $framesPerSecond Maximum number of frames the peer can send per
     *     second before being throttled.
     *
     * @return self
     *
     * @throws \Error if number is less than 1.
     */
    public function withFramesPerSecondLimit(int $framesPerSecond): self
    {
        if ($framesPerSecond < 1) {
            throw new \Error('$bytesPerSecond must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->framesPerSecondLimit = $framesPerSecond;

        return $clone;
    }

    /**
     * @return int Maximum message size that can be received from the remote endpoint.
     *     If a larger message is received, the connection is ended with a POLICY_VIOLATION.
     */
    public function getMessageSizeLimit(): int
    {
        return $this->messageSizeLimit;
    }

    /**
     * @param int $messageSizeLimit  Maximum message size that can be received
     *     from the remote endpoint. If a larger message is received, the connection
     *     is ended with a POLICY_VIOLATION. Default is 10485760 (10MB)
     *
     * @return self
     *
     * @throws \Error if number is less than 1.
     */
    public function withMessageSizeLimit(int $messageSizeLimit): self
    {
        if ($messageSizeLimit < 1) {
            throw new \Error('$messageSizeLimit must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->messageSizeLimit = $messageSizeLimit;

        return $clone;
    }

    /**
     * @return bool If true ends the connection if a binary frame is received.
     */
    public function isTextOnly(): bool
    {
        return $this->textOnly;
    }

    /**
     * @param bool $textOnly If true ends the connection if a binary frame is received.
     *
     * @return self
     */
    public function withTextOnly(bool $textOnly): self
    {
        $clone = clone $this;
        $clone->textOnly = $textOnly;

        return $clone;
    }

    /**
     * @return bool If true validates that all text received and sent is UTF-8.
     */
    public function isValidateUtf8(): bool
    {
        return $this->validateUtf8;
    }

    /**
     * @param bool $validateUtf8 If true validates that all text received and sent is UTF-8.
     *
     * @return self
     */
    public function withValidateUtf8(bool $validateUtf8): self
    {
        $clone = clone $this;
        $clone->validateUtf8 = $validateUtf8;

        return $clone;
    }

    /**
     * @return int Number of seconds to wait to receive peer close frame.
     */
    public function getClosePeriod(): int
    {
        return $this->closePeriod;
    }

    /**
     * @param int $closePeriod Number of seconds to wait to receive peer close
     *     frame. Default is 3.
     *
     * @return self
     *
     * @throws \Error if number is less than 1.
     */
    public function withClosePeriod(int $closePeriod): self
    {
        if ($closePeriod < 1) {
            throw new \Error('$closePeriod must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->closePeriod = $closePeriod;

        return $clone;
    }

    /**
     * @return bool Whether to request or accept per-message compression.
     */
    public function isCompressionEnabled(): bool
    {
        return $this->compressionEnabled;
    }

    /**
     * @return self Enables requesting or accepting per-message compression.
     */
    public function withCompression(): self
    {
        $clone = clone $this;
        $clone->compressionEnabled = true;

        return $clone;
    }

    /**
     * @return self Disables requesting or accepting per-message compression.
     */
    public function withoutCompression(): self
    {
        $clone = clone $this;
        $clone->compressionEnabled = false;

        return $clone;
    }

    /**
     * @return bool If enabled, sends a ping frame to the peer every X seconds (determined
     *     by the heartbeat period) if there is no other activity on the connection.
     */
    public function isHeartbeatEnabled(): bool
    {
        return $this->heartbeatEnabled;
    }

    /**
     * @return self Enables heartbeat; If enabled, sends a ping frame to the
     *     peer every X seconds (determined by the heartbeat period) if there
     *     is no other activity on the connection.
     */
    public function withHeartbeat(): self
    {
        $clone = clone $this;
        $clone->heartbeatEnabled = true;

        return $clone;
    }

    /**
     * @return self Disables heartbeat; If disabled, will not periodically send
     *     a ping frame to the peer during timetrames of inactivity on the connection.
     */
    public function withoutHeartbeat(): self
    {
        $clone = clone $this;
        $clone->heartbeatEnabled = false;

        return $clone;
    }

    /**
     * @return int Duration in seconds between pings or other connection activity if the heartbeat is enabled.
     */
    public function getHeartbeatPeriod(): int
    {
        return $this->heartbeatPeriod;
    }

    /**
     * @param int $heartbeatPeriod Duration in seconds between pings or other
     *     connection activity if the heartbeat is enabled. Default is 10.
     *
     * @return self
     *
     * @throws \Error if number is less than 1.
     */
    public function withHeartbeatPeriod(int $heartbeatPeriod): self
    {
        if ($heartbeatPeriod < 1) {
            throw new \Error('$heartbeatPeriod must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->heartbeatPeriod = $heartbeatPeriod;

        return $clone;
    }

    /**
     * @return int The number of unanswered pings before the connection is closed.
     */
    public function getQueuedPingLimit(): int
    {
        return $this->queuedPingLimit;
    }

    /**
     * @param int $queuedPingLimit The number of unanswered pings before the connection is closed.
     *
     * @return self
     *
     * @throws \Error if number is less than 1.
     */
    public function withQueuedPingLimit(int $queuedPingLimit): self
    {
        if ($queuedPingLimit < 1) {
            throw new \Error('$queuedPingLimit must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->queuedPingLimit = $queuedPingLimit;

        return $clone;
    }
}
