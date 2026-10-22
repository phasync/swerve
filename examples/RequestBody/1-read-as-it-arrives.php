<?php

use Swerve\ClientRequest;
use Swerve\Psr\RequestBody;
use Swerve\AsyncMode;

$app = AsyncMode::serve(null, function (ClientRequest $request) {
    $length = $request->getRequestHeaders()['content-length'][0] ?? null;
    $body   = new RequestBody($request, null === $length ? null : (int) $length);   // a PSR-7 stream

    $lines = 0;
    while ('' !== ($bytes = $body->read(8192))) {   // waits for the next bytes; '' only at the end
        $lines += substr_count($bytes, "\n");
    }
    $request->write("received {$body->getSize()} bytes, $lines lines\n");
});

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
$csv = "id,name\n1,ann\n2,bob\n3,cy\n";
serve(__FILE__, ["POST /import HTTP/1.1\r\nHost: example\r\nContent-Length: " . strlen($csv) . "\r\n\r\n$csv"]);
