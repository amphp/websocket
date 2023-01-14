<?php declare(strict_types=1);

namespace Amp\Websocket\Parser;

use Amp\Websocket\Compression\CompressionContext;

interface WebsocketParserFactory
{
    public function createParser(
        WebsocketFrameHandler $frameHandler,
        bool $masked,
        ?CompressionContext $compressionContext = null,
    ): WebsocketParser;
}
