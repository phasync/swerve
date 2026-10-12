<?php

use Swerve\ClientRequest;
use Swerve\RequestHandler;
use Swerve\ServerSentEvents;

$app = new RequestHandler(function (ClientRequest $request) {
    $events = new ServerSentEvents($request);
    for ($i = 0; $i < 2; ++$i) {
        phasync::sleep(0.05);       // in production: some seconds without news
        $events->comment('keep-alive');   // the browser ignores it; a client that left makes it throw
    }
    $events->send('news at last');
});

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
serve(__FILE__, ["GET /news HTTP/1.1\r\nHost: example\r\n\r\n"]);
