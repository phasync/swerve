<?php

use Swerve\AsyncMode;
use Swerve\ClientRequest;
use Swerve\WebSocket;

// What a framework adapter does: decide with handshake(), let middleware add headers, then upgrade()
$app = AsyncMode::serve(null, function (ClientRequest $request) {
    $decision = WebSocket::handshake($request->getMethod(), $request->getProtocolVersion(), $request->getRequestHeaders(), false);
    if (!$decision->accepted()) {
        $request->sendResponseHeaders($decision->status, $decision->headers);
        $request->write($decision->body);

        return;
    }
    $headers = $decision->headers + ['x-served-by' => 'my-framework'];   // middleware decorates
    WebSocket::upgrade($request, $headers, function (WebSocket $ws) {
        $ws->send('upgraded by the adapter');
    });
});

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and talk to it as a browser would
require __DIR__ . '/../serve.php';
websocket(__FILE__, '/socket', []);
