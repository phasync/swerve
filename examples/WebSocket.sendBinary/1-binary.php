<?php

use Swerve\ClientRequest;
use Swerve\Swerve;
use Swerve\WebSocket;

$app = static function () {
    Swerve::upgrade(static function (ClientRequest $request) {
        WebSocket::from($request, function (WebSocket $ws) {
            $ws->sendBinary(pack('nN', 1, 42));     // bytes, not text: any value
            $ws->send('text must be UTF-8');
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
websocket(__FILE__, '/binary', []);
