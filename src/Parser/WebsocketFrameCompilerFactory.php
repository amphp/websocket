<?php declare(strict_types=1);

namespace Amp\Websocket\Parser;

use Amp\Websocket\Compression\CompressionContext;

interface WebsocketFrameCompilerFactory
{
    public function createFrameCompiler(
        bool $masked,
        ?CompressionContext $compressionContext = null,
    ): WebsocketFrameCompiler;
}
