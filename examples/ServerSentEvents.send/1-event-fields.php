<?php

use Swerve\ClientRequest;
use Swerve\RequestHandler;
use Swerve\ServerSentEvents;

$app = new RequestHandler(function (ClientRequest $request) {
    $events = new ServerSentEvents($request);
    $events->send('plain message');                                  // EventSource's onmessage
    $events->send("two\nlines", event: 'note');                      // addEventListener('note', ...)
    $events->send('{"price":101.5}', event: 'tick', id: '42', retry: 5000);
});

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
serve(__FILE__, ["GET /events HTTP/1.1\r\nHost: example\r\n\r\n"]);
