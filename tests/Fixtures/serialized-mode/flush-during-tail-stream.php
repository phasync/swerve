<?php

// A PSR-7 handler returns a response at once, with a body (phasync\Psr\UnbufferedStream) that a
// tail coroutine fills afterwards -- exactly the shape docs/requests-and-responses.md calls out
// ("a body that a coroutine writes after the handler returned... streams through"). That
// coroutine calls flush() once per chunk, as Symfony's StreamedResponse callback always does
// (so any Laravel/Symfony app streaming through Octane's worker model hits this).

use phasync\Psr\Response;
use phasync\Psr\UnbufferedStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Swerve;

while ($request = Swerve::accept(null, function (ServerRequestInterface $req): ResponseInterface {
    $stream = new UnbufferedStream();
    phasync::go(static function () use ($stream): void {
        foreach (['one', 'two', 'three'] as $chunk) {
            $stream->append($chunk."\n");
            flush();
            phasync::sleep(0.02);
        }
        $stream->end();
    });

    return new Response(200, ['content-type' => ['text/plain']], $stream);
})) {
    phasync::await($request);
}
