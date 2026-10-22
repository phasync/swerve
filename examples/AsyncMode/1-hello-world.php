<?php

use Swerve\AsyncMode;
use Swerve\ClientRequest;

// swerve.php returns the mode; swerve calls its handler once for each request
$app = AsyncMode::serve(null, function (ClientRequest $request) {
    $request->sendResponseHeaders(200, ['content-type' => 'text/plain', 'content-length' => '13']);
    $request->write("Hello, world\n");
});                // returning ends the response; the connection serves its next request

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
serve(__FILE__, ["GET / HTTP/1.1\r\nHost: example\r\n\r\n"]);
