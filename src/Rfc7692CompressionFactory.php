<?php

namespace Amp\Websocket;

final class Rfc7692CompressionFactory implements CompressionContextFactory
{
    public function fromClientHeader(string $headerIn, ?string &$headerOut): ?CompressionContext
    {
        return Rfc7692Compression::fromClientHeader($headerIn, $headerOut);
    }

    public function fromServerHeader(string $header): ?CompressionContext
    {
        return Rfc7692Compression::fromServerHeader($header);
    }

    public function createRequestHeader(): string
    {
        return Rfc7692Compression::createRequestHeader();
    }
}
