<?php

use Swerve\ClientRequest;
use Swerve\RequestHandler;
use Swerve\Swerve;

$app = new RequestHandler(function (ClientRequest $request) {
    // false when the timeout passes first
    $request->write('must close within 0.1 s: ' . var_export(Swerve::awaitShutdown(0.1), true) . "\n");
});

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
serve(__FILE__, ["GET / HTTP/1.1\r\nHost: example\r\n\r\n"]);
