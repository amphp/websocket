<?php

namespace Amp\Websocket;

use cash\LRUCache;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\weakClosure;

class DefaultHeartbeatQueue implements HeartbeatQueue
{
    /** @var array<int, \WeakReference<WebsocketClient>> */
    private array $clients = [];

    private readonly string $watcher;

    /** @var LRUCache&\Traversable Least-recently-used cache of next ping (heartbeat) times. */
    private readonly LRUCache $heartbeatTimeouts;

    /**
     * @param positive-int $queuedPingLimit
     * @param positive-int $heartbeatPeriod
     */
    public function __construct(
        int $queuedPingLimit = 3,
        private readonly int $heartbeatPeriod = 10,
    ) {
        $this->heartbeatTimeouts = new class(\PHP_INT_MAX) extends LRUCache implements \IteratorAggregate {
            public function getIterator(): \Iterator
            {
                yield from $this->data;
            }
        };

        $this->watcher = EventLoop::repeat(1, weakClosure(function () use ($queuedPingLimit): void {
            $now = \time();

            foreach ($this->heartbeatTimeouts as $clientId => $expiryTime) {
                if ($expiryTime >= $now) {
                    break;
                }

                /** @var WebsocketClient|null $client */
                $client = ($this->clients[$clientId] ?? null)?->get();
                if (!$client) {
                    $this->heartbeatTimeouts->remove($clientId);
                    continue;
                }

                $this->heartbeatTimeouts->put($clientId, $now + $this->heartbeatPeriod);

                if ($client->getUnansweredPingCount() > $queuedPingLimit) {
                    async($client->close(...), CloseCode::POLICY_VIOLATION, 'Exceeded unanswered PING limit')->ignore();
                    continue;
                }

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
        $this->heartbeatTimeouts->put($id, \time() + $this->heartbeatPeriod);
    }

    public function remove(WebsocketClient $client): void
    {
        $id = $client->getId();
        $this->heartbeatTimeouts->remove($id);
        unset($this->clients[$id]);
    }
}
