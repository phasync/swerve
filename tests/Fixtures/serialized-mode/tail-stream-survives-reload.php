<?php

// A PSR-7 handler returns at once, with a body (phasync\Psr\UnbufferedStream) that a tail
// coroutine fills afterwards over time -- the documented pattern (docs/requests-and-responses.md:
// "a body that a coroutine writes after the handler returned... streams through"). docs/README.md
// says a SIGHUP reload's old worker "finish[es] the requests in flight" before any
// WorkerStoppingException. This checks that a request still streaming its body counts.

use phasync\Psr\Response;
use phasync\Psr\UnbufferedStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Swerve;

while ($request = Swerve::accept(null, function (ServerRequestInterface $req): ResponseInterface {
    $stream = new UnbufferedStream();
    phasync::go(static function () use ($stream): void {
        foreach (['one', 'two', 'three', 'four', 'five'] as $chunk) {
            $stream->append($chunk."\n");
            phasync::sleep(0.1);
        }
        $stream->end();
    });

    return new Response(200, ['content-type' => ['text/plain']], $stream);
})) {
    phasync::await($request);
}
