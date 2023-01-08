<?php declare(strict_types=1);

namespace Amp\Websocket;

use Revolt\EventLoop;
use function Amp\async;
use function Amp\weakClosure;

class DefaultHeartbeatQueue implements HeartbeatQueue
{
    /** @var array<int, \WeakReference<WebsocketClient>> */
    private array $clients = [];

    private readonly string $watcher;

    /** @var array<int, int> Least-recently-used cache of next ping (heartbeat) times. */
    private array $heartbeatTimeouts = [];

    /** @var int Cached current time to avoid syscall on each update. */
    private int $now;

    /**
     * @param positive-int $queuedPingLimit
     * @param positive-int $heartbeatPeriod
     */
    public function __construct(
        int $queuedPingLimit = 3,
        private readonly int $heartbeatPeriod = 10,
    ) {
        /** @psalm-suppress TypeDoesNotContainType */
        if ($queuedPingLimit <= 0) {
            throw new \ValueError('Queued ping limit must be greater than 0');
        }

        /** @psalm-suppress TypeDoesNotContainType */
        if ($this->heartbeatPeriod <= 0) {
            throw new \ValueError('Heartbeat period must be greater than 0');
        }

        $this->now = \time();

        $this->watcher = EventLoop::repeat(1, weakClosure(function () use ($queuedPingLimit): void {
            $this->now = \time();

            foreach ($this->heartbeatTimeouts as $clientId => $expiryTime) {
                if ($expiryTime >= $this->now) {
                    break;
                }

                /** @var WebsocketClient|null $client */
                $client = ($this->clients[$clientId] ?? null)?->get();
                if (!$client) {
                    unset($this->heartbeatTimeouts[$clientId]);
                    continue;
                }

                if ($client->getUnansweredPingCount() > $queuedPingLimit) {
                    $this->remove($client);
                    async($client->close(...), CloseCode::POLICY_VIOLATION, 'Exceeded unanswered PING limit')->ignore();
                    continue;
                }

                $this->update($client);

                async($client->ping(...))->ignore();
            }
        }));

        EventLoop::unreference($this->watcher);
    }

    public function __destruct()
    {
        EventLoop::cancel($this->watcher);
    }

    public function insert(WebsocketClient $client): void
    {
        $this->clients[$client->getId()] = \WeakReference::create($client);
        $this->update($client);
    }

    public function update(WebsocketClient $client): void
    {
        $id = $client->getId();
        \assert(isset($this->clients[$id]));
        unset($this->heartbeatTimeouts[$id]); // Unset to force ordering to end of list.
        $this->heartbeatTimeouts[$id] = $this->now + $this->heartbeatPeriod;
    }

    public function remove(WebsocketClient $client): void
    {
        $id = $client->getId();
        unset($this->clients[$id], $this->heartbeatTimeouts[$id]);
    }
}
