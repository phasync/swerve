<?php

use Swerve\ClientRequest;
use Swerve\Swerve;
use Swerve\HeadersSentException;

$app = static function () {
    Swerve::upgrade(static function (ClientRequest $request) {
        $request->write("partial output\n");  // commits an implicit 200
        try {
            $request->sendResponseHeaders(500);
        } catch (HeadersSentException) {
            $request->write("too late for a 500: the head is out\n");
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
serve(__FILE__, ["GET / HTTP/1.1\r\nHost: example\r\n\r\n"]);
