<?php

namespace Swerve;

/**
 * A protocol server, such as HTTP/1.1 (--http) or FastCGI (--fastcgi): it turns what arrives on
 * its listener into PSR-7 requests, gives each to the Dispatcher, and sends the response
 * back in its protocol.
 */
interface ServerInterface
{
    /**
     * Open the listener. Throws when that fails, before the worker tells the master it is ready.
     */
    public function listen(): void;

    /**
     * Accept and serve connections until drained: returns once drain() was called and every
     * connection has ended. Call from inside phasync::run(), after listen().
     */
    public function run(): void;

    /**
     * Stop accepting and let the requests in flight finish; run() then returns.
     */
    public function drain(): void;
}
