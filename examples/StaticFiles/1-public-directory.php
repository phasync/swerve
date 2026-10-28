<?php

use Swerve\Swerve;

// Files below public/ are served; every other request reaches the application
$app = static function () {
    \http_response_code(404);
    \header('Content-Type: text/plain');
    echo "No page at {$_SERVER['REQUEST_URI']}\n";
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
    "GET /hello.txt HTTP/1.1\r\nHost: example\r\n\r\n",
    "GET /missing.txt HTTP/1.1\r\nHost: example\r\n\r\n",
]);
