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
    echo getmypid();
}));
