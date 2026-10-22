<?php

namespace Swerve;

/**
 * A protocol server, such as HTTP/1.1 (`--http`).
 *
 * It turns what arrives on its listener into {@see ClientRequest}s and gives each to the
 * application's handler. A worker calls `listen()`, then
 * `run()` inside `phasync::run()`, and `drain()` when it is to stop.
 *
 * ```php
 * $server->listen();                    // throws when the address can't be bound
 * phasync::run($server->run(...));      // returns once drain() was called and every connection ended
 * ```
 *
 * @see Swerve\ClientRequest
 *
 * @topic request-handlers
 */
interface ServerInterface
{
    /**
     * Open the listener.
     *
     * Throws when that fails, before the worker tells the master it is ready.
     */
    public function listen(): void;

    /**
     * Accept and serve connections until drained.
     *
     * Returns once {@see ServerInterface::drain()} was called and every connection has ended.
     * Call it from inside `phasync::run()`, after {@see ServerInterface::listen()}.
     */
    public function run(): void;

    /**
     * Stop accepting; {@see ServerInterface::run()} returns once every connection has ended.
     * Meanwhile the worker stops the requests in flight with a {@see WorkerStoppingException}
     * at their wait, and the server ends its protocol for each (a `503` for a response not
     * yet begun).
     *
     * Upgraded connections stay until they close, or until the worker's stop ends their
     * coroutines.
     *
     * @param bool $linger a recycle: the worker keeps its upgraded connections for now
     */
    public function drain(bool $linger = false): void;
}
