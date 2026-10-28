<?php

use Swerve\ClientRequest;
use Swerve\Swerve;
use Swerve\WebSocket;

// For code that sends the 101 itself: run() takes over the connection after it
$app = static function () {
    Swerve::upgrade(static function (ClientRequest $request) {
        $decision = WebSocket::handshake($request->getMethod(), $request->getProtocolVersion(), $request->getRequestHeaders(), false);
        $request->sendResponseHeaders(101, $decision->headers);
        WebSocket::run($request, function (WebSocket $ws) {
            foreach ($ws as $message) {
                $ws->send(strtoupper($message));
                return;
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
websocket(__FILE__, '/socket', ['shout']);
