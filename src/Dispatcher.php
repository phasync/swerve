<?php

namespace Swerve;

use phasync;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Swerve\Util\RequestContextFactory;

/**
 * Where a request meets the application, whatever protocol it came in.
 *
 * The application's handler runs, and its response is sent, inside a phasync context of the
 * request's own, which the coroutines the request starts share: request-scoped state can hang
 * on `phasync::getContext()` (mini's does). The context is created when the request's code first
 * asks for one, so a request that never does costs none. The context knows its request (see
 * {@see LoggingContext::$request}), which is how a report from deep inside can say which request it is about.
 *
 * The response is sent inside the context too, so phasync::finally() in the handler runs once
 * all of it is sent (as after fastcgi_finish_request()). dispatch() returns once the coroutines
 * the request started have ended, like phasync::run(), so a connection serves its next request
 * only after the work of the previous one; each protocol marks its response complete to the
 * client in respond(), before that wait. It runs in the coroutine of the caller: a coroutine per
 * request cost about half of a hello-world request.
 *
 * ```php
 * $dispatcher = new Dispatcher($app, $logger);          // $app is a PSR-15 RequestHandlerInterface
 * $dispatcher->dispatch($request, $responder);          // what a ServerInterface does per request
 * ```
 *
 * @see Swerve\ServerInterface
 * @see Swerve\ResponderInterface
 */
final class Dispatcher
{
    /**
     * Make a dispatcher for the application's handler.
     *
     * @param RequestHandlerInterface $handler the application
     * @param LoggerInterface         $logger  receives what a request's coroutines throw when nobody awaits them
     */
    public function __construct(private readonly RequestHandlerInterface $handler, private readonly LoggerInterface $logger)
    {
    }

    /**
     * Run the application's handler for `$request` and send its response through `$responder`.
     *
     * Returns once the coroutines the request started have ended.
     *
     * @param ServerRequestInterface $request   the request, as the protocol server parsed it
     * @param ResponderInterface     $responder sends the response in the protocol the request came in
     *
     * @return mixed what `$responder` returns
     */
    public function dispatch(ServerRequestInterface $request, ResponderInterface $responder): mixed
    {
        return phasync::withContext(fn () => $responder->respond($request, $this->handler->handle($request)), new RequestContextFactory($this->logger, $request));
    }
}
