<?php

namespace Swerve;

/**
 * A protocol server, such as HTTP/1.1 (`--http`) or FastCGI (`--fastcgi`).
 *
 * It turns what arrives on its listener into PSR-7 requests, gives each to the
 * {@see Dispatcher}, and sends the response back in its protocol. A worker calls `listen()`, then
 * `run()` inside `phasync::run()`, and `drain()` when it is to stop.
 *
 * ```php
 * $server->listen();                    // throws when the address can't be bound
 * phasync::run($server->run(...));      // returns once drain() was called and every connection ended
 * ```
 *
 * @see Swerve\Dispatcher
 * @see Swerve\ResponderInterface
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
     * With `$linger`, a server that has upgraded connections keeps them until they
     * close; calling `drain()` again, without `$linger`, ends them.
     *
     * @param bool $linger keep the upgraded connections for now
     */
    public function drain(bool $linger = false): void;
}
