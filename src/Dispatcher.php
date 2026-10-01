<?php

namespace Swerve;

use phasync;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Swerve\Util\RequestContextFactory;

/**
 * Where a request meets the application, whatever protocol it came in: the application's
 * handler runs, and its response is sent, inside a phasync context of the request's own, which
 * the coroutines the request starts share: request-scoped state can hang on
 * phasync::getContext() (mini's does). The context is created when the request's code first
 * asks for one, so a request that never does costs none.
 *
 * The response is sent inside the context too, so phasync::finally() in the handler runs once
 * all of it is sent (as after fastcgi_finish_request()). dispatch() returns once the coroutines
 * the request started have ended, like phasync::run(), so a connection serves its next request
 * only after the work of the previous one; each protocol marks its response complete to the
 * client in respond(), before that wait. It runs in the coroutine of the caller: a coroutine per
 * request cost about half of a hello-world request.
 */
final class Dispatcher
{
    private readonly RequestContextFactory $contextFactory;

    public function __construct(private readonly RequestHandlerInterface $handler, LoggerInterface $logger)
    {
        $this->contextFactory = new RequestContextFactory($logger);
    }

    /**
     * @return mixed what $responder returns
     */
    public function dispatch(ServerRequestInterface $request, ResponderInterface $responder): mixed
    {
        return phasync::withContext(fn () => $responder->respond($request, $this->handler->handle($request)), $this->contextFactory);
    }
}
