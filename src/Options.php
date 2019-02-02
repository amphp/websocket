<?php

namespace Amp\Http\Websocket;

class Options
{
    private $streamThreshold = 32768;
    private $frameSplitThreshold = 32768;
    private $bytesPerSecondLimit = 8 << 16;
    private $framesPerSecondLimit = 100;
    private $maximumFrameSize = 2097152;
    private $maximumMessageSize = 10485760;
    private $textOnly = false;
    private $validateUtf8 = true;
    private $closePeriod = 3;
    private $compressionEnabled = true;

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

    public function getMaximumFrameSize(): int
    {
        return $this->maximumFrameSize;
    }

    public function withMaximumFrameSize(int $maximumFrameSize): self
    {
        if ($maximumFrameSize < 1) {
            throw new \Error('$maximumFrameSize must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->maximumFrameSize = $maximumFrameSize;

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

    public function getMaximumMessageSize(): int
    {
        return $this->maximumMessageSize;
    }

    public function withMaximumMessageSize(int $maximumMessageSize): self
    {
        if ($maximumMessageSize < 1) {
            throw new \Error('$maximumMessageSize must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->maximumMessageSize = $maximumMessageSize;

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
}
