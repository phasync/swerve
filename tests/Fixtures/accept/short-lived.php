<?php
use Swerve\Swerve;
use Swerve\WebSocket;

// WordPress's shape: one request per lifecycle, so a WebSocket can't stay in the worker
phasync::await(Swerve::accept(null, function () {
    if ('/ws' === $_SERVER['REQUEST_URI']) {
        $greeting = 'echo';
        Swerve::upgrade(static function (WebSocket $ws) use ($greeting): void {
            foreach ($ws as $message) {
                $ws->send("$greeting $message from " . getmypid());
            }
        });

        return;
    }
    if ('/ws-resource' === $_SERVER['REQUEST_URI']) {
        $file = fopen(__FILE__, 'r');
        try {
            Swerve::upgrade(static function (WebSocket $ws) use ($file): void {
            });
        } catch (LogicException $e) {
            echo 'refused: ', $e->getMessage();
        }

        return;
    }
    echo getmypid();
}));
