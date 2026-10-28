<?php

use Swerve\ClientRequest;
use Swerve\Swerve;

$app = static function () {
    Swerve::upgrade(static function (ClientRequest $request) {
        $request->sendResponseHeaders(200, ['content-type' => 'text/plain']);
        $request->flush();                    // the client sees the head now, before any work is done

        foreach (['parsing', 'indexing', 'done'] as $step) {
            phasync::sleep(0.05);              // the work
            $request->write("$step\n");       // each write is a chunk on the wire
        }
    });
};

if (debug_backtrace()) {   // swerve runs this file: it is the application's swerve.php
    while (Swerve::accept(null, $app)) {
    }

    return;
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
serve(__FILE__, ["GET /rebuild HTTP/1.1\r\nHost: example\r\n\r\n"]);
