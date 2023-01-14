<?php declare(strict_types=1);

namespace Amp\Websocket\Compression;

interface CompressionContextFactory
{
    /**
     * Create a compression context from a header received from a websocket client request.
     *
     * @param string $headerIn Header from request.
     * @param-out string|null $headerOut Sec-Websocket-Extension response header if an instance
     * of {@see CompressionContext} is returned, otherwise {@code null}.
     */
    public function fromClientHeader(string $headerIn, ?string &$headerOut): ?CompressionContext;

    /**
     * Create a compression context from a header received from a websocket server response.
     *
     * @param string $header Header from response.
     */
    public function fromServerHeader(string $header): ?CompressionContext;

    /**
     * @return string Header value for Sec-Websocket-Extension header.
     */
    public function createRequestHeader(): string;
}