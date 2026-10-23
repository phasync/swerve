<?php

namespace Swerve\Util\Classic;

use Serializor\Serializor;
use Swerve\ClientRequest;
use Swerve\ServerSentEvents;
use Swerve\WebSocket;

/**
 * A Classic script's client, handed over to the worker: the script called WebSocket::setup() or
 * ServerSentEvents::setup() (classic-client.php), and its response head names the handover (see
 * {@see ServiceServer::HANDOVER}). The runner stops forwarding the script's response, and the
 * worker runs the script's closure on the client's connection, as a coroutine, for as long as it
 * likes; the script goes on as if its client had left.
 *
 * @internal
 */
final class Handover
{
    public static function run(ClientRequest $request, string $id): void
    {
        [$kind, $closure, $options] = Serializor::unserialize(ServiceServer::take($id));
        match ($kind) {
            'websocket' => WebSocket::from($request, $closure, ...$options),
            'sse'       => $closure(new ServerSentEvents($request, ...$options)),
        };
    }
}
