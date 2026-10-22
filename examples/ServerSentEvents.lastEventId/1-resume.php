<?php

use Swerve\AsyncMode;
use Swerve\ClientRequest;
use Swerve\ServerSentEvents;

$app = AsyncMode::serve(null, function (ClientRequest $request) {
    $news   = [1 => 'first', 2 => 'second', 3 => 'third'];
    $events = new ServerSentEvents($request);
    $after  = (int) ($events->lastEventId() ?? 0);   // a reconnecting browser says what it saw last
    foreach ($news as $id => $item) {
        if ($id > $after) {
            $events->send($item, id: (string) $id);
        }
    }
});

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
serve(__FILE__, ["GET /news HTTP/1.1\r\nHost: example\r\nLast-Event-ID: 1\r\n\r\n"]);
