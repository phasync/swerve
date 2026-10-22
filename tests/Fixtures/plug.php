<?php

// Without ext-phasync: application code runs through the plug, see PlugTest

use Swerve\AsyncMode;
use Swerve\ClientRequest;

$boot = PHP_SAPI;

return AsyncMode::serve(null, static function (ClientRequest $request) use ($boot): void {
    if ('/sleep' === $request->getTarget()) {
        \sleep(1); // native sleep(): the plug makes it a cooperative wait
    }
    $request->sendResponseHeaders(200, ['content-type' => 'text/plain']);
    $request->write("boot=$boot request=" . PHP_SAPI . ' name=' . \php_sapi_name());
});
