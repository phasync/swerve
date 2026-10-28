<?php

use Swerve\Swerve;

$app = static function () {
    \http_response_code(404);
};

if (debug_backtrace()) {   // swerve runs this file: it is the application's swerve.php
    while (Swerve::accept(__DIR__ . '/public', $app)) {
    }

    return;
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
touch(__DIR__ . '/public/hello.txt', 1767225600);   // a fixed date, for the same output every run
serve(__FILE__, [
    "GET /hello.txt HTTP/1.1\r\nHost: example\r\nRange: bytes=0-4\r\n\r\n",                    // 206, the first 5 bytes
    "GET /hello.txt HTTP/1.1\r\nHost: example\r\nIf-None-Match: \"6955b900-12\"\r\n\r\n",      // 304, the client has it
    "GET /serve.php HTTP/1.1\r\nHost: example\r\n\r\n",                                        // never a PHP source
]);
