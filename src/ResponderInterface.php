<?php

namespace Swerve;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What sends the application's response in the protocol the request arrived in.
 *
 * It is the connection of a server. {@see Dispatcher::dispatch()}
 * calls it with the application's response.
 *
 * ```php
 * final class Echoing implements ResponderInterface
 * {
 *     public function respond(ServerRequestInterface $request, ResponseInterface $response): mixed
 *     {
 *         echo $response->getStatusCode(), "\n";   // a protocol would write the response to its client
 *
 *         return $response->getStatusCode();
 *     }
 * }
 * ```
 *
 * @see Swerve\Dispatcher
 * @see Swerve\ServerInterface
 */
interface ResponderInterface
{
    /**
     * Send `$response`, the answer to `$request`, and return once its body is sent.
     *
     * What it returns goes back to the caller of {@see Dispatcher::dispatch()}.
     *
     * @param ServerRequestInterface $request  the request being answered
     * @param ResponseInterface      $response what the application answered
     *
     * @return mixed anything the server wants back from dispatch()
     */
    public function respond(ServerRequestInterface $request, ResponseInterface $response): mixed;
}
