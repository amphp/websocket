<?php

namespace Amp\Websocket;

final class Options
{
    /**
     * Number of bytes that will be buffered when streaming a message body before sending a frame.
     *
     * @var int
     */
    private $streamThreshold = 32768; // 32KB

    /**
     * If a message exceeds this number of bytes, it is split into multiple frames, each no bigger than this value.
     *
     * @var int
     */
    private $frameSplitThreshold = 32768; // 32KB

    /**
     * Maximum number of bytes the peer can send per second before being throttled.
     *
     * @var int
     */
    private $bytesPerSecondLimit = 1048576; // 1MB

    /**
     * Maximum number of frames the peer can send per second before being throttled.
     *
     * @var int
     */
    private $framesPerSecondLimit = 100;

    /**
     * Maximum frame size that can be received from the peer. If a larger frame
     * is received, the connection is ended with a POLICY_VIOLATION
     *
     * @var int
     */
    private $frameSizeLimit = 2097152; // 2MB

    /**
     * Maximum message size that can be received from the remote endpoint. If a
     * larger message is received, the connection is ended with a POLICY_VIOLATION.
     *
     * @var int
     */
    private $messageSizeLimit = 10485760; // 10MB

    /**
     * Ends the connection if a binary frame is received.
     *
     * @var bool
     */
    private $textOnly = false;

    /**
     * Validates that all text received and sent is UTF-8.
     *
     * @var bool
     */
    private $validateUtf8 = true;

    /**
     * Number of seconds to wait to receive peer close frame.
     *
     * @var int
     */
    private $closePeriod = 3;

    /**
     * Whether to request or accept per-message compression.
     *
     * @var bool
     */
    private $compressionEnabled = false;

    /**
     * If enabled, sends a ping frame to the peer every X seconds (determined by
     * the heartbeat period) if there is no other activity on the connection.
     *
     * @var bool
     */
    private $heartbeatEnabled = true;

    /**
     * Duration between pings or other connection activity.
     *
     * @var int
     */
    private $heartbeatPeriod = 10;

    /**
     * Number of unanswered pings before the connection is closed.
     *
     * @var int
     */
    private $queuedPingLimit = 3;

    public function getStreamThreshold(): int
    {
        return $this->streamThreshold;
    }

    public function withStreamThreshold(int $streamThreshold): self
    {
        if ($streamThreshold < 1) {
            throw new \Error('$streamThreshold must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->streamThreshold = $streamThreshold;

        return $clone;
    }

    public function getFrameSplitThreshold(): int
    {
        return $this->frameSplitThreshold;
    }

    public function withFrameSplitThreshold(int $frameSplitThreshold): self
    {
        if ($frameSplitThreshold < 1) {
            throw new \Error('$frameSplitThreshold must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->frameSplitThreshold = $frameSplitThreshold;

        return $clone;
    }

    public function getFrameSizeLimit(): int
    {
        return $this->frameSizeLimit;
    }

    public function withFrameSizeLimit(int $frameSizeLimit): self
    {
        if ($frameSizeLimit < 1) {
            throw new \Error('$frameSizeLimit must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->frameSizeLimit = $frameSizeLimit;

        return $clone;
    }

    public function getBytesPerSecondLimit(): int
    {
        return $this->bytesPerSecondLimit;
    }

    public function withBytesPerSecondLimit(int $bytesPerSecond): self
    {
        if ($bytesPerSecond < 1) {
            throw new \Error('$bytesPerSecond must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->bytesPerSecondLimit = $bytesPerSecond;

        return $clone;
    }

    public function getFramesPerSecondLimit(): int
    {
        return $this->bytesPerSecondLimit;
    }

    public function withFramesPerSecondLimit(int $framesPerSecond): self
    {
        if ($framesPerSecond < 1) {
            throw new \Error('$bytesPerSecond must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->framesPerSecondLimit = $framesPerSecond;

        return $clone;
    }

    public function getMessageSizeLimit(): int
    {
        return $this->messageSizeLimit;
    }

    public function withMessageSizeLimit(int $messageSizeLimit): self
    {
        if ($messageSizeLimit < 1) {
            throw new \Error('$messageSizeLimit must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->messageSizeLimit = $messageSizeLimit;

        return $clone;
    }

    public function isTextOnly(): bool
    {
        return $this->textOnly;
    }

    public function withTextOnly(bool $textOnly): self
    {
        $clone = clone $this;
        $clone->textOnly = $textOnly;

        return $clone;
    }

    public function isValidateUtf8(): bool
    {
        return $this->validateUtf8;
    }

    public function withValidateUtf8(bool $validateUtf8): self
    {
        $clone = clone $this;
        $clone->validateUtf8 = $validateUtf8;

        return $clone;
    }

    public function getClosePeriod(): int
    {
        return $this->closePeriod;
    }

    public function withClosePeriod(int $closePeriod): self
    {
        if ($closePeriod < 1) {
            throw new \Error('$closePeriod must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->closePeriod = $closePeriod;

        return $clone;
    }

    public function isCompressionEnabled(): bool
    {
        return $this->compressionEnabled;
    }

    public function withCompression(): self
    {
        $clone = clone $this;
        $clone->compressionEnabled = true;

        return $clone;
    }

    public function withoutCompression(): self
    {
        $clone = clone $this;
        $clone->compressionEnabled = false;

        return $clone;
    }

    public function isHeartbeatEnabled(): bool
    {
        return $this->heartbeatEnabled;
    }

    public function withHeartbeat(): self
    {
        $clone = clone $this;
        $clone->heartbeatEnabled = true;

        return $clone;
    }

    public function withoutHeartbeat(): self
    {
        $clone = clone $this;
        $clone->heartbeatEnabled = false;

        return $clone;
    }

    public function getHeartbeatPeriod(): int
    {
        return $this->heartbeatPeriod;
    }

    public function withHeartbeatPeriod(int $heartbeatPeriod): self
    {
        if ($heartbeatPeriod < 1) {
            throw new \Error('$heartbeatPeriod must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->heartbeatPeriod = $heartbeatPeriod;

        return $clone;
    }

    public function getQueuedPingLimit(): int
    {
        return $this->queuedPingLimit;
    }

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
