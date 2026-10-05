<?php

namespace Swerve;

/**
 * What an application's swerve.php returns: the callable that serves each HTTP exchange.
 *
 * ```php
 * // swerve.php
 * return new Swerve\RequestHandler(function (Swerve\ClientRequest $request) {
 *     $request->write("Hello, world\n");
 * });
 * ```
 *
 * The callable gets a {@see ClientRequest} and returns when it is done: the response is then
 * finished, and the connection serves its next request.
 */
final class RequestHandler
{
    /** @param \Closure(ClientRequest): void $handler */
    public function __construct(public readonly \Closure $handler)
    {
    }
}
