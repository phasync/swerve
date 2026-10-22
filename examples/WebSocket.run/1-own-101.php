<?php

use Swerve\AsyncMode;
use Swerve\ClientRequest;
use Swerve\WebSocket;

// For code that sends the 101 itself: run() takes over the connection after it
$app = AsyncMode::serve(null, function (ClientRequest $request) {
    $decision = WebSocket::handshake($request->getMethod(), $request->getProtocolVersion(), $request->getRequestHeaders(), false);
    $request->sendResponseHeaders(101, $decision->headers);
    WebSocket::run($request, function (WebSocket $ws) {
        foreach ($ws as $message) {
            $ws->send(strtoupper($message));
            return;
        }
    });
});

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and talk to it as a browser would
require __DIR__ . '/../serve.php';
websocket(__FILE__, '/socket', ['shout']);
