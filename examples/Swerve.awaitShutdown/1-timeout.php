<?php

use Swerve\ClientRequest;
use Swerve\Swerve;

$app = static function () {
    Swerve::upgrade(static function (ClientRequest $request) {
        // false when the timeout passes first
        $request->write('must close within 0.1 s: ' . var_export(Swerve::awaitShutdown(0.1), true) . "\n");
    });
};

if (debug_backtrace()) {   // swerve runs this file: it is the application's swerve.php
    while (Swerve::accept(null, $app)) {
    }

    return;
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
serve(__FILE__, ["GET / HTTP/1.1\r\nHost: example\r\n\r\n"]);
