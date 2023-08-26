<?php declare(strict_types=1);

namespace Amp\Websocket\Internal;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Pipeline\Queue;
use Amp\Websocket\ClosedException;
use Amp\Websocket\WebsocketMessage;

/** @internal */
final class WebsocketClientQueues
{
    /** @var DeferredFuture<null>|null */
    private ?DeferredFuture $closeDeferred;

    /** @var Queue<WebsocketMessage> */
    public readonly Queue $messageQueue;

    /** @var Queue<string>|null */
    public ?Queue $currentMessageQueue = null;

    public function __construct(
    ) {
        $this->closeDeferred = new DeferredFuture();
        $this->messageQueue = new Queue();
    }

    public function isComplete(): bool
    {
        return $this->closeDeferred === null;
    }

    public function complete(): void
    {
        $this->closeDeferred?->complete();
        $this->closeDeferred = null;
    }

    public function await(Cancellation $cancellation): void
    {
        $this->closeDeferred?->getFuture()->await($cancellation);
    }

    public function close(int $code, string $reason): void
    {
        if (!$this->messageQueue->isComplete()) {
            $this->messageQueue->complete();
        }

        $this->currentMessageQueue?->error(new ClosedException(
            'Connection closed while streaming message body',
            $code,
            $reason,
        ));
        $this->currentMessageQueue = null;
    }
}
