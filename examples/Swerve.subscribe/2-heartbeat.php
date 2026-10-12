<?php

use Swerve\Swerve;

phasync::run(function () {
    phasync::go(function () {
        phasync::sleep(0.5);
        Swerve::publish('quiet', 'finally');
    });

    // null after each 0.2 s without a message: time for a keep-alive
    foreach (Swerve::subscribe('quiet', heartbeat: 0.2) as $message) {
        if (null === $message) {
            echo "keep-alive\n";
            continue;
        }
        echo "message: $message\n";
        break;
    }
});
