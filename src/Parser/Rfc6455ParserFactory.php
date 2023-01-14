<?php declare(strict_types=1);

namespace Amp\Websocket\Parser;

use Amp\Websocket\Compression\CompressionContext;

class Rfc6455ParserFactory implements WebsocketParserFactory
{
    public function __construct(
        private readonly bool $textOnly = Rfc6455Parser::DEFAULT_TEXT_ONLY,
        private readonly bool $validateUtf8 = Rfc6455Parser::DEFAULT_VALIDATE_UTF8,
        private readonly int $messageSizeLimit = Rfc6455Parser::DEFAULT_MESSAGE_SIZE_LIMIT,
        private readonly int $frameSizeLimit = Rfc6455Parser::DEFAULT_FRAME_SIZE_LIMIT,
    ) {
    }

    public function createParser(
        WebsocketFrameHandler $frameHandler,
        bool $masked,
        ?CompressionContext $compressionContext = null,
    ): WebsocketParser {
        return new Rfc6455Parser(
            $frameHandler,
            $masked,
            $compressionContext,
            $this->textOnly,
            $this->validateUtf8,
            $this->messageSizeLimit,
            $this->frameSizeLimit,
        );
    }
}
