<?php

use Swerve\ClientRequest;
use Swerve\RequestHandler;
use Swerve\Swerve;

// Keep date_default_timezone_set() per request, though it is process-wide PHP state
$zones = new WeakMap();
Swerve::onRequestSwitch(
    resume: function (ClientRequest $request) use ($zones) {
        date_default_timezone_set($zones[$request] ?? 'UTC');
    },
    suspend: function (ClientRequest $request) use ($zones) {
        $zones[$request] = date_default_timezone_get();
    },
);

$app = new RequestHandler(function (ClientRequest $request) {
    $zone = substr($request->getTarget(), 1);   // /Europe/Oslo
    date_default_timezone_set($zone);
    phasync::sleep(0.1);                         // the other request runs meanwhile, and sets its own
    $request->write("asked for $zone, still in " . date_default_timezone_get() . "\n");
});

if (debug_backtrace()) {
    return $app;   // swerve loads this file: it is the application's swerve.php
}

// Run directly: serve the application with swerve, and send it requests
require __DIR__ . '/../serve.php';
serve(__FILE__, [   // at the same time, in one worker
    "GET /Europe/Oslo HTTP/1.1\r\nHost: example\r\n\r\n",
    "GET /America/New_York HTTP/1.1\r\nHost: example\r\n\r\n",
]);
