<?php

use Swerve\ClientRequest;
use Swerve\Swerve;
use Swerve\StopReason;
use Swerve\WorkerStoppingException;

$app = static function () {
    Swerve::upgrade(static function (ClientRequest $request) {
        $request->write("waiting for news\n");
        try {
            phasync::sleep(30);
        } catch (WorkerStoppingException $e) {
            phasync::shielded(fn () => $request->write(match ($e->reason) {
                StopReason::Shutdown => "the server is going away\n",
                StopReason::Reload   => "new code is starting: reconnect\n",
            }));
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
serve(__FILE__, ["GET /news HTTP/1.1\r\nHost: example\r\n\r\n"], stop: SIGHUP);   // a reload, once it answers
