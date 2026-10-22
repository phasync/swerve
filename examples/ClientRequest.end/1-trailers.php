<?php

use Swerve\AsyncMode;
use Swerve\ClientRequest;

$app = AsyncMode::serve(null, function (ClientRequest $request) {
    // Trailers: named in the head, sent after a chunked body, for what is known only at the end
    $request->sendResponseHeaders(200, ['content-type' => 'text/plain', 'trailer' => 'x-checksum']);
    $hash = hash_init('crc32b');
    foreach (['one ', 'two ', 'three'] as $part) {
        $request->write($part);
        hash_update($hash, $part);
    }
    $request->end(['x-checksum' => hash_final($hash)]);
});

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
serve(__FILE__, ["GET / HTTP/1.1\r\nHost: example\r\nTE: trailers\r\n\r\n"]);
