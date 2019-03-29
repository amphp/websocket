<?php

namespace Amp\Websocket;

final class Options
{
    private $streamThreshold = 32768; // 32KB
    private $frameSplitThreshold = 32768; // 32KB
    private $bytesPerSecondLimit = 1048576; // 1MB
    private $framesPerSecondLimit = 100;
    private $frameSizeLimit = 2097152; // 2MB
    private $messageSizeLimit = 10485760; // 10MB
    private $textOnly = false;
    private $validateUtf8 = true;
    private $closePeriod = 3;
    private $compressionEnabled = false;
    private $heartbeatEnabled = true;
    private $heartbeatPeriod = 10;
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
     * Creates an Options object with values as documented on the accessor methods except for the following:
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
