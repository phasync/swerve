<?php
use Swerve\Swerve;
use Swerve\WebSocket;

// One request at a time, awaiting each: an inline WebSocket holds up the next request until it
// closes, a moved one does not
while ($request = Swerve::accept(null, function () {
    if ('/ws' === $_SERVER['REQUEST_URI']) {
        Swerve::upgrade(static function (WebSocket $ws): void {
            foreach ($ws as $message) {
                $ws->send("echo $message");
            }
        });

        return;
    }
    if ('/ws-moved' === $_SERVER['REQUEST_URI']) {
        Swerve::upgrade('ws_echo', __DIR__ . '/ws-boot.php');

        return;
    }
    echo 'hello';
})) {
    phasync::await($request);
}
