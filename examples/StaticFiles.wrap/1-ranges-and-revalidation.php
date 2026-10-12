<?php

use Swerve\ClientRequest;
use Swerve\RequestHandler;
use Swerve\StaticFiles;

$app = new RequestHandler((new StaticFiles(__DIR__ . '/../StaticFiles/public'))->wrap(function (ClientRequest $request) {
    $request->sendResponseHeaders(404);
    $request->end();
}));

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
touch(__DIR__ . '/../StaticFiles/public/hello.txt', 1767225600);   // a fixed date, for the same output every run
serve(__FILE__, [
    "GET /hello.txt HTTP/1.1\r\nHost: example\r\nRange: bytes=0-4\r\n\r\n",                    // 206, the first 5 bytes
    "GET /hello.txt HTTP/1.1\r\nHost: example\r\nIf-None-Match: \"6955b900-12\"\r\n\r\n",      // 304, the client has it
    "GET /serve.php HTTP/1.1\r\nHost: example\r\n\r\n",                                        // never a PHP source
]);
