<?php

namespace Amp\Websocket;

use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

class DefaultRateLimiter implements RateLimiter
{
    /** @var array<int, int> */
    private array $bytesReadInLastSecond = [];

    /** @var array<int, int> */
    private array $framesReadInLastSecond = [];

    /** @var Suspension[] */
    private array $rateSuspensions = [];

    private readonly string $watcher;

    public function __construct(
        private readonly int $bytesPerSecondLimit = 1048576, // 1MB
        private readonly int $framesPerSecondLimit = 100,
    ) {
        $rateSuspensions = &$this->rateSuspensions;
        $bytesReadInLastSecond = &$this->bytesReadInLastSecond;
        $framesReadInLastSecond = &$this->framesReadInLastSecond;
        $this->watcher = EventLoop::repeat(1, static function (string $watcher) use (
            &$rateSuspensions,
            &$bytesReadInLastSecond,
            &$framesReadInLastSecond,
        ): void {
            $bytesReadInLastSecond = [];
            $framesReadInLastSecond = [];

            if (!empty($rateSuspensions)) {
                EventLoop::unreference($watcher);

                foreach ($rateSuspensions as $suspension) {
                    $suspension->resume();
                }

                $rateSuspensions = [];
            }
        });
    }

    public function __destruct()
    {
        EventLoop::cancel($this->watcher);
    }

    public function addToByteCount(WebsocketClient $client, int $bytes): void
    {
        $id = $client->getId();
        $count = $this->bytesReadInLastSecond[$id] = ($this->bytesReadInLastSecond[$id] ?? 0) + $bytes;

        if ($count >= $this->bytesPerSecondLimit) {
            $this->rateSuspensions[$id] ??= EventLoop::getSuspension();
            EventLoop::reference($this->watcher);
        }
    }

    public function addToFrameCount(WebsocketClient $client, int $frames): void
    {
        $id = $client->getId();
        $count = $this->framesReadInLastSecond[$id] = ($this->framesReadInLastSecond[$id] ?? 0) + $frames;

        if ($count >= $this->framesPerSecondLimit) {
            $this->rateSuspensions[$id] ??= EventLoop::getSuspension();
            EventLoop::reference($this->watcher);
        }
    }

    public function getSuspension(WebsocketClient $client): ?Suspension
    {
        return $this->rateSuspensions[$client->getId()] ?? null;
    }
}
