<?php

use Swerve\ClientRequest;
use Swerve\Swerve;
use Swerve\WebSocket;

$app = static function () {
    Swerve::upgrade(static function (ClientRequest $request) {
        WebSocket::from($request, function (WebSocket $ws) {
            Swerve::onShutdown(fn () => phasync::shielded(function () use ($ws) {
                $ws->send('draining: ' . var_export(Swerve::draining(), true));
                $ws->end(1001);
            }));
            $ws->send('draining: ' . var_export(Swerve::draining(), true));
            foreach ($ws as $message) {
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
websocket(__FILE__, '/live', [], stop: SIGTERM);   // swerve is stopped after the first message
