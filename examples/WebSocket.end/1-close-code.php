<?php

use Swerve\AsyncMode;
use Swerve\ClientRequest;
use Swerve\WebSocket;

$app = AsyncMode::serve(null, function (ClientRequest $request) {
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

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and talk to it as a browser would
require __DIR__ . '/../serve.php';
websocket(__FILE__, '/game', ['3', '7']);
