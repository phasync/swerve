<?php

use Swerve\Swerve;

phasync::run(function () {
    // Three coroutines (in a server: workers) want to run the nightly report; one may
    foreach (['a', 'b', 'c'] as $who) {
        phasync::go(function () use ($who) {
            if ($claim = Swerve::claim('nightly-report')->acquire()) {
                echo "$who runs the report\n";
                phasync::sleep(0.1);
            } else {
                echo "$who: someone else is on it\n";
            }
        });                                   // $claim is released when it goes out of scope
    }
});

echo Swerve::claim('nightly-report')->available() ? "free again\n" : "still held\n";
