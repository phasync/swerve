<?php

use Swerve\AsyncMode;
use Swerve\ClientRequest;

$app = AsyncMode::serve(null, function (ClientRequest $request) {
    // A 1xx goes out at once: here 103 Early Hints, while the page is still being made
    $request->sendResponseHeaders(103, ['link' => '</style.css>; rel=preload; as=style']);

    // The final head waits for the first body bytes, and goes out with them
    $request->sendResponseHeaders(201, ['content-type' => 'application/json', 'location' => '/items/7']);
    $request->write('{"id":7}');
});

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
serve(__FILE__, ["POST /items HTTP/1.1\r\nHost: example\r\nContent-Length: 0\r\n\r\n"]);
