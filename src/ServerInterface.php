<?php

namespace Swerve;

/**
 * A protocol server, such as HTTP/1.1 (`--http`).
 *
 * It turns what arrives on its listener into {@see ClientRequest}s and gives each to the
 * application's {@see RequestHandler}. A worker calls `listen()`, then
 * `run()` inside `phasync::run()`, and `drain()` when it is to stop.
 *
 * ```php
 * $server->listen();                    // throws when the address can't be bound
 * phasync::run($server->run(...));      // returns once drain() was called and every connection ended
 * ```
 *
 * @see Swerve\ClientRequest
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
     * Stop accepting and let the requests in flight finish; {@see ServerInterface::run()} then returns.
     *
     * Upgraded connections stay until they close, or until the worker's stop ends their
     * coroutines.
     *
     * @param bool $linger a recycle: the worker keeps its upgraded connections for now
     */
    public function drain(bool $linger = false): void;
}
