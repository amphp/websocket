<?php

namespace Amp\Websocket;

use cash\LRUCache;
use Revolt\EventLoop;
use function Amp\async;

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
        $this->heartbeatTimeouts = $heartbeatTimeouts = new class(\PHP_INT_MAX) extends LRUCache implements \IteratorAggregate {
            public function getIterator(): \Iterator
            {
                yield from $this->data;
            }
        };

        $clients = &$this->clients;
        $this->watcher = EventLoop::repeat(1, static function (string $watcher) use (
            &$clients,
            $heartbeatTimeouts,
            $queuedPingLimit,
            $heartbeatPeriod,
        ): void {
            $now = \time();

            foreach ($heartbeatTimeouts as $clientId => $expiryTime) {
                if ($expiryTime >= $now) {
                    break;
                }

                /** @var WebsocketClient|null $client */
                $client = ($clients[$clientId] ?? null)?->get();
                if (!$client) {
                    $heartbeatTimeouts->remove($clientId);
                    continue;
                }

                $heartbeatTimeouts->put($clientId, $now + $heartbeatPeriod);

                if ($client->getUnansweredPingCount() > $queuedPingLimit) {
                    async($client->close(...), CloseCode::POLICY_VIOLATION, 'Exceeded unanswered PING limit')->ignore();
                    continue;
                }

                async($client->ping(...))->ignore();
            }
        });
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
