<?php declare(strict_types=1);

namespace Amp\Websocket;

enum WebsocketCount
{
    case BytesRead;
    case BytesSent;
    case FramesRead;
    case FramesSent;
    case MessagesRead;
    case MessagesSent;
    case PingsRead;
    case PingsSent;
    case PongsRead;
    case PongsSent;
    case UnansweredPings;
}
