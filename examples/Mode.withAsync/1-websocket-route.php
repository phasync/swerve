<?php

use Swerve\ClassicMode;
use Swerve\ClientRequest;
use Swerve\WebSocket;

// A Classic application (PHP's own request lifecycle, as under PHP-FPM) with a WebSocket route
// that always runs in Swerve's workers, concurrently, whatever Classic's own requests run as
$app = ClassicMode::serve(__DIR__ . '/public')
    ->withAsync('/ws/', function (ClientRequest $request) {
        WebSocket::from($request, function (WebSocket $ws) {
            foreach ($ws as $message) {             // ends when the connection closes
                if ('bye' === $message) {
                    return;                          // returning closes the connection (1000)
                }
                $ws->send("echo: $message");
            }
        });
    });

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and talk to the WebSocket route as a browser would
require __DIR__ . '/../serve.php';
websocket(__FILE__, '/ws/', ['hello', 'world', 'bye']);
