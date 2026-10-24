<?php

declare(strict_types=1);

namespace Swerve\Psr;

use Psr\Http\Message\StreamInterface;

/**
 * The body of a `101`: a tunnel (a raw upgrade, or a WebSocket) whose handler is handed the
 * connection. The bridge tells it how to close the connection itself, so a handler that closes
 * it ends the exchange at once — including a response write stalled on a client that stopped
 * reading, which is otherwise waiting for something that just left.
 *
 * @internal
 */
interface TunnelBody extends StreamInterface
{
    /**
     * @param \Closure(): void $close closes the connection, discarding what is not written yet
     */
    public function onHandlerClose(\Closure $close): void;
}
