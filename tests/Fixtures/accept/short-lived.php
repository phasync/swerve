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
    if ('/ws-state' === strtok($_SERVER['REQUEST_URI'], '?')) {
        date_default_timezone_set('Asia/Tokyo');
        Swerve::upgrade(static function (WebSocket $ws): void {
            foreach ($ws as $message) {
                $ws->send(json_encode([$_GET, date_default_timezone_get()]));
            }
        });

        return;
    }
    if ('/ws-busy' === $_SERVER['REQUEST_URI']) {
        Swerve::upgrade(static function (WebSocket $ws): void {
            foreach ($ws as $message) {
                if ('block' === $message) {
                    // About two seconds in one C call: no coroutine of this process runs meanwhile
                    password_hash('x', PASSWORD_BCRYPT, ['cost' => 15]);
                }
                $ws->send("$message from " . getmypid());
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
