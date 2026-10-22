<?php

use Swerve\AsyncMode;
use Swerve\ClientRequest;
use Swerve\ServerSentEvents;

$app = AsyncMode::serve(null, function (ClientRequest $request) {
    $events = new ServerSentEvents($request);   // the head goes out at once
    foreach (['build started', 'tests passed', 'deployed'] as $n => $status) {
        phasync::sleep(0.05);
        $events->send($status, id: (string) ($n + 1));
    }
});                                            // the stream ends when the handler returns

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
serve(__FILE__, ["GET /deploys HTTP/1.1\r\nHost: example\r\nAccept: text/event-stream\r\n\r\n"]);
