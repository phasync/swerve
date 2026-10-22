<?php

/*
 * An application that starts a coroutine as it loads, running for the worker's life.
 */

use Swerve\AsyncMode;
use Swerve\ClientRequest;

$ticks = 0;
phasync::go(static function () use (&$ticks) {
    while (true) {
        phasync::sleep(0.05);
        ++$ticks;
    }
});

return AsyncMode::serve(null, static function (ClientRequest $r) use (&$ticks) {
    $r->sendResponseHeaders(200, ['Content-Length' => (string) \strlen((string) $ticks)]);
    $r->write((string) $ticks);
});
