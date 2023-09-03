<?php declare(strict_types=1);

namespace Amp\Websocket;

enum WebsocketTime
{
    case Connected;
    case Closed;
    case LastRead;
    case LastSent;
    case LastDataRead;
    case LastDataSent;
    case LastHeartbeat;
}
