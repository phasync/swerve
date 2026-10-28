<?php

use Swerve\ClientRequest;
use Swerve\Swerve;
use Swerve\WebSocket;

$app = static function () {
    Swerve::upgrade(static function (ClientRequest $request) {
        WebSocket::from($request, function (WebSocket $ws) {
            // When the worker must close its connections: say why, in the protocol
            Swerve::onShutdown(fn () => phasync::shielded(fn () => $ws->end(1001, 'server restarting')));

            $ws->send('welcome');
            foreach ($ws as $message) {
                $ws->send($message);
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
websocket(__FILE__, '/live', [], stop: SIGTERM);   // swerve is stopped after the welcome
