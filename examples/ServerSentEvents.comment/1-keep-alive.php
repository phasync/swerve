<?php

use Swerve\ClientRequest;
use Swerve\Swerve;
use Swerve\ServerSentEvents;

$app = static function () {
    Swerve::upgrade(static function (ClientRequest $request) {
        $events = new ServerSentEvents($request);
        for ($i = 0; $i < 2; ++$i) {
            phasync::sleep(0.05);       // in production: some seconds without news
            $events->comment('keep-alive');   // the browser ignores it; a client that left makes it throw
        }
        $events->send('news at last');
    });
};

if (debug_backtrace()) {   // swerve runs this file: it is the application's swerve.php
    while (Swerve::accept(null, $app)) {
    }

    return;
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
serve(__FILE__, ["GET /news HTTP/1.1\r\nHost: example\r\n\r\n"]);
