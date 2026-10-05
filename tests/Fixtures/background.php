<?php

/*
 * An application that starts a coroutine as it loads, running for the worker's life.
 */

use Swerve\ClientRequest;
use Swerve\RequestHandler;

$ticks = 0;
phasync::go(static function () use (&$ticks) {
    while (true) {
        phasync::sleep(0.05);
        ++$ticks;
    }
});

return new RequestHandler(static function (ClientRequest $r) use (&$ticks) {
    $r->sendResponseHeaders(200, ['Content-Length' => (string) \strlen((string) $ticks)]);
    $r->write((string) $ticks);
});
