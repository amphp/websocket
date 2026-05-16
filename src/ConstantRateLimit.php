<?php declare(strict_types=1);

namespace Amp\Websocket;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Interval;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use function Amp\weakClosure;

final class ConstantRateLimit implements WebsocketRateLimit
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var array<int, int> */
    private array $bytesReadInLastSecond = [];

    /** @var array<int, int> */
    private array $framesReadInLastSecond = [];

    /** @var array<int, Suspension> */
    private array $rateSuspensions = [];

    private readonly Interval $interval;

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

        $this->interval = new Interval(1, weakClosure(function (): void {
            $this->bytesReadInLastSecond = [];
            $this->framesReadInLastSecond = [];

            if (!empty($this->rateSuspensions)) {
                $this->interval->unreference();

                foreach ($this->rateSuspensions as $suspension) {
                    $suspension->resume();
                }

                $this->rateSuspensions = [];
            }
        }), reference: false);
    }

    public function notifyBytesReceived(int $clientId, int $byteCount): void
    {
        $count = $this->bytesReadInLastSecond[$clientId] = ($this->bytesReadInLastSecond[$clientId] ?? 0) + $byteCount;

        if ($count >= $this->bytesPerSecondLimit) {
            $suspension = $this->rateSuspensions[$clientId] ??= EventLoop::getSuspension();
            $this->interval->reference();
            $suspension->suspend();
        }
    }

    public function notifyFramesReceived(int $clientId, int $frameCount): void
    {
        $count = $this->framesReadInLastSecond[$clientId] = ($this->framesReadInLastSecond[$clientId] ?? 0) + $frameCount;

        if ($count >= $this->framesPerSecondLimit) {
            $suspension = $this->rateSuspensions[$clientId] ??= EventLoop::getSuspension();
            $this->interval->reference();
            $suspension->suspend();
        }
    }
}
