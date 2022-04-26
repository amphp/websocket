<?php

namespace Amp\Websocket;

use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use function Amp\weakClosure;

class DefaultRateLimiter implements RateLimiter
{
    /** @var array<int, int> */
    private array $bytesReadInLastSecond = [];

    /** @var array<int, int> */
    private array $framesReadInLastSecond = [];

    /** @var Suspension[] */
    private array $rateSuspensions = [];

    private readonly string $watcher;

    /**
     * @param positive-int $bytesPerSecondLimit
     * @param positive-int $framesPerSecondLimit
     */
    public function __construct(
        private readonly int $bytesPerSecondLimit = 1048576, // 1MB
        private readonly int $framesPerSecondLimit = 100,
    ) {
        /** @psalm-suppress TypeDoesNotContainType */
        if ($this->bytesPerSecondLimit <= 0) {
            throw new \ValueError('Bytes-per-second limit must be greater than 0');
        }

        /** @psalm-suppress TypeDoesNotContainType */
        if ($this->framesPerSecondLimit <= 0) {
            throw new \ValueError('Frames-per-second limit must be greater than 0');
        }

        $this->watcher = EventLoop::repeat(1, weakClosure(function (string $watcher): void {
            $this->bytesReadInLastSecond = [];
            $this->framesReadInLastSecond = [];

            if (!empty($this->rateSuspensions)) {
                EventLoop::unreference($watcher);

                foreach ($this->rateSuspensions as $suspension) {
                    $suspension->resume();
                }

                $this->rateSuspensions = [];
            }
        }));

        EventLoop::unreference($this->watcher);
    }

    public function __destruct()
    {
        EventLoop::cancel($this->watcher);
    }

    public function notifyBytesReceived(WebsocketClient $client, int $bytes): void
    {
        $id = $client->getId();
        $count = $this->bytesReadInLastSecond[$id] = ($this->bytesReadInLastSecond[$id] ?? 0) + $bytes;

        if ($count >= $this->bytesPerSecondLimit) {
            $suspension = $this->rateSuspensions[$id] ??= EventLoop::getSuspension();
            EventLoop::reference($this->watcher);
            $suspension->suspend();
        }
    }

    public function notifyFramesReceived(WebsocketClient $client, int $frames): void
    {
        $id = $client->getId();
        $count = $this->framesReadInLastSecond[$id] = ($this->framesReadInLastSecond[$id] ?? 0) + $frames;

        if ($count >= $this->framesPerSecondLimit) {
            $suspension = $this->rateSuspensions[$id] ??= EventLoop::getSuspension();
            EventLoop::reference($this->watcher);
            $suspension->suspend();
        }
    }
}
