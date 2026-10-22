<?php

use Swerve\AsyncMode;
use Swerve\ClientRequest;
use Swerve\Swerve;
use Swerve\WebSocket;

$app = AsyncMode::serve(null, function (ClientRequest $request) {
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

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and talk to it as a browser would
require __DIR__ . '/../serve.php';
websocket(__FILE__, '/live', [], stop: SIGTERM);   // swerve is stopped after the first message
