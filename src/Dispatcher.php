<?php

namespace Swerve;

use phasync;
use phasync\Psr\StreamFactory;
use phasync\Psr\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Swerve\Http\Superglobals;
use Swerve\Http\VirtualSapi;
use Swerve\Util\RequestContextFactory;
use Swerve\Util\StrayOutput;

/**
 * Where a request meets the application, whatever protocol it came in.
 *
 * The application's handler runs, and its response is sent, inside a phasync context of the
 * request's own, which the coroutines the request starts share: request-scoped state can hang
 * on `phasync::getContext()` (mini's does). The context is created when the request's code first
 * asks for one, so a request that never does costs none. The context knows its request (see
 * {@see LoggingContext::$request}), which is how a report from deep inside, such as the stray
 * output guard's, can say which request it is about.
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
        ++StrayOutput::$inFlight; // the stray output guard acts only while a request is handled
        try {
            if (Swerve::virtualizing()) {
                return $this->virtual($request, $responder);
            }

            return phasync::withContext(fn () => $responder->respond($request, $this->handler->handle($request)), new RequestContextFactory($this->logger, $request));
        } finally {
            --StrayOutput::$inFlight;
        }
    }

    /**
     * Swerve::virtualize(): the handler runs as a request of its own, in a context that swaps the
     * superglobals (PHP builds them as the request starts, so the context exists before). What it
     * echoes streams to the client; a response it returns without echoing is sent as usual.
     */
    private function virtual(ServerRequestInterface $request, StreamingResponderInterface $responder): mixed
    {
        $sapi = new VirtualSapi($request, $responder);

        return phasync::withContext(function () use ($request, $responder, $sapi) {
            $response = \phasync\ext\virtualize(function () use ($request, $sapi) {
                if ($sapi->form()) {
                    // PHP has read the body: the PSR request gets what PHP made of it
                    $request = $request->withParsedBody($_POST)->withUploadedFiles(self::uploadedFiles($_FILES));
                }

                return $this->handler->handle($request);
            }, $sapi);
            if (!$sapi->started && $response instanceof ResponseInterface) {
                return $responder->respond($request, $response);
            }
            $sapi->commit();

            return $responder->streamEnd();
        }, new Superglobals($this->logger, $request, Superglobals::current()));
    }

    /** PHP's $_FILES as PSR-7 uploaded files, nested as the field names are. */
    private static function uploadedFiles(array $files): array
    {
        $tree = [];
        foreach ($files as $field => $file) {
            $tree[$field] = self::uploadedFile($file['name'], $file['type'], $file['tmp_name'], $file['error'], $file['size']);
        }

        return $tree;
    }

    private static function uploadedFile(mixed $name, mixed $type, mixed $path, mixed $error, mixed $size): mixed
    {
        if (!\is_array($name)) {
            return new UploadedFile(\UPLOAD_ERR_OK === $error ? $path : StreamFactory::create(''), $name, $type, $size, $error);
        }
        $nested = [];
        foreach ($name as $key => $_) {
            $nested[$key] = self::uploadedFile($name[$key], $type[$key], $path[$key], $error[$key], $size[$key]);
        }

        return $nested;
    }
}
