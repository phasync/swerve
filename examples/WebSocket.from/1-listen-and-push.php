<?php

use Swerve\ClientRequest;
use Swerve\Swerve;
use Swerve\WebSocket;

$app = static function () {
    Swerve::upgrade(static function (ClientRequest $request) {
        WebSocket::from($request, function (WebSocket $ws) {
            $ws->onMessage->listen(function (string $data) {     // inbound: events
                Swerve::publish('chat', $data);                  // to every worker's subscribers
            });
            foreach (Swerve::subscribe('chat') as $out) {        // outbound: plain sequential code
                if ('/quit' === $out) {
                    return;
                }
                $ws->send("someone said: $out");
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
websocket(__FILE__, '/chat', ['hi all', 'anyone here?', '/quit']);
