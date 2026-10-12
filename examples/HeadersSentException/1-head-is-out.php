<?php

use Swerve\ClientRequest;
use Swerve\HeadersSentException;
use Swerve\RequestHandler;

$app = new RequestHandler(function (ClientRequest $request) {
    $request->write("partial output\n");  // commits an implicit 200
    try {
        $request->sendResponseHeaders(500);
    } catch (HeadersSentException) {
        $request->write("too late for a 500: the head is out\n");
    }
});

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
serve(__FILE__, ["GET / HTTP/1.1\r\nHost: example\r\n\r\n"]);
