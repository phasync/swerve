<?php

use Swerve\ClientRequest;
use Swerve\Swerve;
use Swerve\WebSocket;

$app = static function () {
    Swerve::upgrade(static function (ClientRequest $request) {
        if (null === $ws = WebSocket::accept($request)) {
            return;                                  // refused: swerve answered 426, 400 or 403
        }
        try {
            $total = 0;
            while (null !== ($message = $ws->receive())) {
                $total += (int) $message;
                $ws->send("total $total");
                if ($total >= 10) {
                    break;
                }
            }
        } finally {
            $ws->end(1000, 'enough');                // with accept(), the handler closes
        }
    });
};

if (debug_backtrace()) {   // swerve runs this file: it is the application's swerve.php
    while (Swerve::accept(null, $app)) {
    }

    return;
}

// Run directly: serve the application with swerve, and talk to it as a browser would
require __DIR__ . '/../serve.php';
websocket(__FILE__, '/sum', ['3', '4', '5']);
