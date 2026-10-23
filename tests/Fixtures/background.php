<?php

/*
 * An application that starts a coroutine as it loads, running for the worker's life.
 */

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Swerve;

$ticks = 0;
phasync::go(static function () use (&$ticks) {
    while (true) {
        phasync::sleep(0.05);
        ++$ticks;
    }
});

while (true) {
    Swerve::accept(null, static function (ServerRequestInterface $r) use (&$ticks): ResponseInterface {
        return new phasync\Psr\Response(200, ['Content-Length' => (string) \strlen((string) $ticks)], new phasync\Psr\StringStream((string) $ticks));
    });
}
