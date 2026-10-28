<?php

use Swerve\ClientRequest;
use Swerve\Swerve;
use Swerve\WebSocket;

$app = static function () {
    Swerve::upgrade(static function (ClientRequest $request) {
        WebSocket::from($request, function (WebSocket $ws) {
            $ws->send('guess a number');
            foreach ($ws as $guess) {
                if ('7' === $guess) {
                    $ws->end(4000, 'you win');       // 4000-4999: codes of your own application
                } else {
                    $ws->send('no');
                }
            }
        });
    });
};

if (debug_backtrace()) {   // swerve runs this file: it is the application's swerve.php
    while (Swerve::accept(null, $app)) {
    }

    return;
}

// Run directly: serve the application with swerve, and talk to it as a browser would
require __DIR__ . '/../serve.php';
websocket(__FILE__, '/game', ['3', '7']);
