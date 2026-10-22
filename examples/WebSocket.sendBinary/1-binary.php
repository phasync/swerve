<?php

use Swerve\AsyncMode;
use Swerve\ClientRequest;
use Swerve\WebSocket;

$app = AsyncMode::serve(null, function (ClientRequest $request) {
    WebSocket::from($request, function (WebSocket $ws) {
        $ws->sendBinary(pack('nN', 1, 42));     // bytes, not text: any value
        $ws->send('text must be UTF-8');
    });
});

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and talk to it as a browser would
require __DIR__ . '/../serve.php';
websocket(__FILE__, '/binary', []);
