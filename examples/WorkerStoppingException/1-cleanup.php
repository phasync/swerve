<?php

use Swerve\ClientRequest;
use Swerve\Swerve;
use Swerve\WorkerStoppingException;

$app = static function () {
    Swerve::upgrade(static function (ClientRequest $request) {
        $request->write("export started\n");
        try {
            phasync::sleep(30);                      // a long job; the worker stops meanwhile
            $request->write("export done\n");
        } catch (WorkerStoppingException $e) {
            // Clean up at once; a write waits, so it goes inside shielded()
            phasync::shielded(fn () => $request->write("stopped: {$e->reason->name}, try again\n"));
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
serve(__FILE__, ["GET /export HTTP/1.1\r\nHost: example\r\n\r\n"], stop: SIGTERM);   // stop swerve once it answers
