<?php
use Swerve\Swerve;
use Swerve\WebSocket;

// One request at a time, awaiting each: a WebSocket handed off must not hold up the next request
while ($request = Swerve::accept(null, function () {
    if ('/ws' === $_SERVER['REQUEST_URI']) {
        Swerve::upgrade(static function (WebSocket $ws): void {
            foreach ($ws as $message) {
                $ws->send("echo $message");
            }
        });

        return;
    }
    echo 'hello';
})) {
    phasync::await($request);
}
