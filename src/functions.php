<?php

namespace Amp\Websocket;

/**
 * Generates the value for the Sec-Websocket-Accept header based on the given Sec-Websocket-Key header value.
 *
 * @param string $key
 *
 * @return string
 */
function generateAcceptFromKey(string $key): string
{
    return \base64_encode(\sha1($key . GUID, true));
}

/**
 * Determines if the Sec-Websocket-Accept value given matches the expected value for the Sec-Websocket-Key header.
 *
 * @param string $accept
 * @param string $key
 *
 * @return bool
 */
function validateAcceptForKey(string $accept, string $key): bool
{
    return $accept === generateAcceptFromKey($key);
}
