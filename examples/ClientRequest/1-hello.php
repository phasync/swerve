<?php

use Swerve\ClientRequest;
use Swerve\RequestHandler;

$app = new RequestHandler(function (ClientRequest $request) {
    $name = $request->getRequestHeaders()['x-name'][0] ?? 'world';
    $request->sendResponseHeaders(200, ['content-type' => 'text/plain']);
    $request->write("Hello, $name: {$request->getMethod()} {$request->getTarget()}\n");
});

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and send it a request
require __DIR__ . '/../serve.php';
serve(__FILE__, ["GET /greeting?lang=en HTTP/1.1\r\nHost: example\r\nX-Name: Ann\r\n\r\n"]);
