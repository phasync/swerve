<?php

use Swerve\ClientRequest;
use Swerve\Swerve;
use Swerve\WebSocket;

$app = static function () {
    Swerve::upgrade(static function (ClientRequest $request) {
        WebSocket::from($request, function (WebSocket $ws) {
            foreach ($ws as $message) {             // ends when the connection closes
                if ('bye' === $message) {
                    return;                          // returning closes the connection (1000)
                }
                $ws->send("echo: $message");
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
websocket(__FILE__, '/echo', ['hello', 'world', 'bye']);
