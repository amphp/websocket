<?php

namespace Amp\Websocket;

class Options
{
    private $streamThreshold = 32768; // 32KB
    private $frameSplitThreshold = 32768; // 32KB
    private $bytesPerSecondLimit = 524288; // 512KB
    private $framesPerSecondLimit = 100;
    private $frameSizeLimit = 2097152; // 2MB
    private $messageSizeLimit = 10485760; // 10MB
    private $textOnly = false;
    private $validateUtf8 = true;
    private $closePeriod = 3;
    private $compressionEnabled = true;

    final public function getStreamThreshold(): int
    {
        return $this->streamThreshold;
    }

    final public function withStreamThreshold(int $streamThreshold): self
    {
        if ($streamThreshold < 1) {
            throw new \Error('$streamThreshold must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->streamThreshold = $streamThreshold;

        return $clone;
    }

    final public function getFrameSplitThreshold(): int
    {
        return $this->frameSplitThreshold;
    }

    final public function withFrameSplitThreshold(int $frameSplitThreshold): self
    {
        if ($frameSplitThreshold < 1) {
            throw new \Error('$frameSplitThreshold must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->frameSplitThreshold = $frameSplitThreshold;

        return $clone;
    }

    final public function getFrameSizeLimit(): int
    {
        return $this->frameSizeLimit;
    }

    final public function withFrameSizeLimit(int $frameSizeLimit): self
    {
        if ($frameSizeLimit < 1) {
            throw new \Error('$frameSizeLimit must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->frameSizeLimit = $frameSizeLimit;

        return $clone;
    }

    final public function getBytesPerSecondLimit(): int
    {
        return $this->bytesPerSecondLimit;
    }

    final public function withBytesPerSecondLimit(int $bytesPerSecond): self
    {
        if ($bytesPerSecond < 1) {
            throw new \Error('$bytesPerSecond must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->bytesPerSecondLimit = $bytesPerSecond;

        return $clone;
    }

    final public function getFramesPerSecondLimit(): int
    {
        return $this->bytesPerSecondLimit;
    }

    final public function withFramesPerSecondLimit(int $framesPerSecond): self
    {
        if ($framesPerSecond < 1) {
            throw new \Error('$bytesPerSecond must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->framesPerSecondLimit = $framesPerSecond;

        return $clone;
    }

    final public function getMessageSizeLimit(): int
    {
        return $this->messageSizeLimit;
    }

    final public function withMessageSizeLimit(int $messageSizeLimit): self
    {
        if ($messageSizeLimit < 1) {
            throw new \Error('$messageSizeLimit must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->messageSizeLimit = $messageSizeLimit;

        return $clone;
    }

    final public function isTextOnly(): bool
    {
        return $this->textOnly;
    }

    final public function withTextOnly(bool $textOnly): self
    {
        $clone = clone $this;
        $clone->textOnly = $textOnly;

        return $clone;
    }

    final public function isValidateUtf8(): bool
    {
        return $this->validateUtf8;
    }

    final public function withValidateUtf8(bool $validateUtf8): self
    {
        $clone = clone $this;
        $clone->validateUtf8 = $validateUtf8;

        return $clone;
    }

    final public function getClosePeriod(): int
    {
        return $this->closePeriod;
    }

    final public function withClosePeriod(int $closePeriod): self
    {
        if ($closePeriod < 1) {
            throw new \Error('$closePeriod must be a positive integer greater than 0');
        }

        $clone = clone $this;
        $clone->closePeriod = $closePeriod;

        return $clone;
    }

    final public function isCompressionEnabled(): bool
    {
        return $this->compressionEnabled;
    }

    final public function withCompression(): self
    {
        $clone = clone $this;
        $clone->compressionEnabled = true;

        return $clone;
    }

    final public function withoutCompression(): self
    {
        $clone = clone $this;
        $clone->compressionEnabled = false;

        return $clone;
    }
}
