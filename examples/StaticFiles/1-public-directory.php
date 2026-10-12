<?php

use Swerve\ClientRequest;
use Swerve\RequestHandler;
use Swerve\StaticFiles;

// Files below public/ are served; every other request reaches the application
$files = new StaticFiles(__DIR__ . '/public');
$app   = new RequestHandler($files->wrap(function (ClientRequest $request) {
    $request->sendResponseHeaders(404, ['content-type' => 'text/plain']);
    $request->write("No page at {$request->getTarget()}\n");
}));

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
touch(__DIR__ . '/public/hello.txt', 1767225600);   // a fixed date, for the same output every run
serve(__FILE__, [
    "GET /hello.txt HTTP/1.1\r\nHost: example\r\n\r\n",
    "GET /missing.txt HTTP/1.1\r\nHost: example\r\n\r\n",
]);
