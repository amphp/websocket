<?php

namespace Amp\Websocket;

final class Code
{
    public const NORMAL_CLOSE = 1000;
    public const GOING_AWAY = 1001;
    public const PROTOCOL_ERROR = 1002;
    public const UNACCEPTABLE_TYPE = 1003;
    // 1004 reserved and unused.
    public const NONE = 1005;
    public const ABNORMAL_CLOSE = 1006;
    public const INCONSISTENT_FRAME_DATA_TYPE = 1007;
    public const POLICY_VIOLATION = 1008;
    public const MESSAGE_TOO_LARGE = 1009;
    public const EXPECTED_EXTENSION_MISSING = 1010;
    public const UNEXPECTED_SERVER_ERROR = 1011;
    public const SERVICE_RESTARTING = 1012;
    public const TRY_AGAIN_LATER = 1013;
    public const BAD_GATEWAY = 1014;
    public const TLS_HANDSHAKE_FAILURE = 1015;

    /**
     * @codeCoverageIgnore Class cannot be instigated.
     */
    private function __construct()
    {
        // no instances allowed
    }
}
