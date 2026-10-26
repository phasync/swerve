<?php

/*
 * An application that starts a coroutine as it loads, running for the worker's life.
 */

use Swerve\ClientRequest;
use Swerve\Swerve;

$ticks = 0;
phasync::go(static function () use (&$ticks) {
    while (true) {
        phasync::sleep(0.05);
        ++$ticks;
    }
});

while (Swerve::accept(null, function () use (&$ticks) {
    Swerve::upgrade(static function (ClientRequest $r) use (&$ticks): void {
        $r->sendResponseHeaders(200, ['Content-Length' => (string) \strlen((string) $ticks)]);
        $r->write((string) $ticks);
    });
})) {
}
