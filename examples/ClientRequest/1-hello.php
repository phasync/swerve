<?php

use Swerve\ClientRequest;
use Swerve\Swerve;

$app = static function () {
    Swerve::upgrade(static function (ClientRequest $request) {
        $name = $request->getRequestHeaders()['x-name'][0] ?? 'world';
        $request->sendResponseHeaders(200, ['content-type' => 'text/plain']);
        $request->write("Hello, $name: {$request->getMethod()} {$request->getTarget()}\n");
    });
};

if (debug_backtrace()) {   // swerve runs this file: it is the application's swerve.php
    while (Swerve::accept(null, $app)) {
    }

    return;
}

// Run directly: serve the application with swerve, and send it a request
require __DIR__ . '/../serve.php';
serve(__FILE__, ["GET /greeting?lang=en HTTP/1.1\r\nHost: example\r\nX-Name: Ann\r\n\r\n"]);
