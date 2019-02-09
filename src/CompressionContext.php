<?php

namespace Amp\Websocket;

interface CompressionContext
{
    /**
     * @return int The RSV value for this compression extension.
     */
    public function getRsv(): int;

    /**
     * @return int Minimum number of bytes a message must be before compressing.
     */
    public function getCompressionThreshold(): int;

    /**
     * Compress the given payload data.
     *
     * @param string $data
     * @param bool $isFinal
     *
     * @return string
     */
    public function compress(string $data, bool $isFinal): string;

    /**
     * Decompress the given payload data. Null should be returned if decompression fails.
     *
     * @param string $data
     * @param bool $isFinal
     *
     * @return string|null
     */
    public function decompress(string $data, bool $isFinal): ?string;
}
