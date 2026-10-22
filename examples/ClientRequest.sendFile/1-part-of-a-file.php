<?php

use Swerve\AsyncMode;
use Swerve\ClientRequest;

$app = AsyncMode::serve(null, function (ClientRequest $request) {
    $file = fopen('php://temp', 'w+');
    fwrite($file, "0123456789abcdefghij");

    // No content-length is computed: give one with the head, or the body is chunked
    $request->sendResponseHeaders(200, ['content-type' => 'text/plain', 'content-length' => '6']);
    $request->sendFile($file, 10, 6);    // 6 bytes from offset 10
    fclose($file);
});

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
serve(__FILE__, ["GET /file HTTP/1.1\r\nHost: example\r\n\r\n"]);
