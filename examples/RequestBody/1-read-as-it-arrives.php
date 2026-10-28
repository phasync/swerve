<?php

use Swerve\ClientRequest;
use Swerve\Swerve;
use Swerve\Psr\RequestBody;

$app = static function () {
    Swerve::upgrade(static function (ClientRequest $request) {
        $length = $request->getRequestHeaders()['content-length'][0] ?? null;
        $body   = new RequestBody($request, null === $length ? null : (int) $length);   // a PSR-7 stream

        $lines = 0;
        while ('' !== ($bytes = $body->read(8192))) {   // waits for the next bytes; '' only at the end
            $lines += substr_count($bytes, "\n");
        }
        $request->write("received {$body->getSize()} bytes, $lines lines\n");
    });
};

if (debug_backtrace()) {   // swerve runs this file: it is the application's swerve.php
    while (Swerve::accept(null, $app)) {
    }

    return;
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
$csv = "id,name\n1,ann\n2,bob\n3,cy\n";
serve(__FILE__, ["POST /import HTTP/1.1\r\nHost: example\r\nContent-Length: " . strlen($csv) . "\r\n\r\n$csv"]);
