<?php

use Swerve\ClientRequest;
use Swerve\RequestHandler;
use Swerve\StopReason;
use Swerve\WorkerStoppingException;

$app = new RequestHandler(function (ClientRequest $request) {
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

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
serve(__FILE__, ["GET /news HTTP/1.1\r\nHost: example\r\n\r\n"], stop: SIGHUP);   // a reload, once it answers
