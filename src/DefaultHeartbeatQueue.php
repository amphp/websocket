<?php

namespace Amp\Websocket;

use cash\LRUCache;
use Revolt\EventLoop;

class DefaultHeartbeatQueue implements HeartbeatQueue
{
    /** @var array<int, \WeakReference<Client>> */
    private array $clients = [];

    private string $watcher;

    /** @var LRUCache&\IteratorAggregate Least-recently-used cache of next ping (heartbeat) times. */
    private LRUCache $heartbeatTimeouts;

    public function __construct(
        int $queuedPingLimit = 3,
        private int $heartbeatPeriod = 10,
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

                /** @var Client|null $client */
                $client = ($clients[$clientId] ?? null)?->get();
                if (!$client) {
                    $heartbeatTimeouts->remove($clientId);
                    continue;
                }

                $heartbeatTimeouts->put($clientId, $now + $heartbeatPeriod);

                if ($client->getUnansweredPingCount() > $queuedPingLimit) {
                    $client->close(Code::POLICY_VIOLATION, 'Exceeded unanswered PING limit');
                    continue;
                }

                $client->ping();
            }
        });
    }

    public function __destruct()
    {
        EventLoop::cancel($this->watcher);
    }

    public function insert(Client $client): void
    {
        $this->clients[$client->getId()] = \WeakReference::create($client);
        $this->update($client);
    }

    public function update(Client $client): void
    {
        $id = $client->getId();
        \assert(isset($this->clients[$id]));
        $this->heartbeatTimeouts->put($id, \time() + $this->heartbeatPeriod);
    }

    public function remove(Client $client): void
    {
        $id = $client->getId();
        $this->heartbeatTimeouts->remove($id);
        unset($this->clients[$id]);
    }
}
