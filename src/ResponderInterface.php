<?php

namespace Swerve;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What sends the application's response in the protocol the request arrived in: the
 * connection (HTTP) or the request (FastCGI) of a server. See Dispatcher.
 */
interface ResponderInterface
{
    /**
     * Send $response, the answer to $request, and return once its body is sent. What it returns
     * goes back to the caller of Dispatcher::dispatch().
     */
    public function respond(ServerRequestInterface $request, ResponseInterface $response): mixed;
}
