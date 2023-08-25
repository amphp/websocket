<?php declare(strict_types=1);

namespace Amp\Websocket;

enum WebsocketClientEventKey
{
    case ConnectedAt;
    case ClosedAt;
    case LastReadAt;
    case LastSentAt;
    case LastDataReadAt;
    case LastDataSentAt;
    case LastHeartbeatAt;
}
