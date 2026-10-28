<?php

use Swerve\ServerSentEvents;
use Swerve\Swerve;

$app = static function () {
    Swerve::upgrade(static function (ServerSentEvents $events) {   // the head goes out at once
        foreach (['build started', 'tests passed', 'deployed'] as $n => $status) {
            phasync::sleep(0.05);
            $events->send($status, id: (string) ($n + 1));
        }
    });                                        // the stream ends when the closure returns
};

if (debug_backtrace()) {   // swerve runs this file: it is the application's swerve.php
    while (Swerve::accept(null, $app)) {
    }

    return;
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
serve(__FILE__, ["GET /deploys HTTP/1.1\r\nHost: example\r\nAccept: text/event-stream\r\n\r\n"]);
