<?php

use Swerve\Swerve;

phasync::run(function () {
    $subscription = Swerve::subscribe('ticks');   // receives from here on
    phasync::go(function () {
        foreach ([1, 2, 3] as $n) {
            Swerve::publish('ticks', $n);
        }
    });

    foreach ($subscription as $tick) {
        echo "tick $tick\n";
        if (2 === $tick) {
            break;
        }
    }
    unset($subscription);                         // its last reference: the subscription ends

    $later = Swerve::subscribe('ticks');
    Swerve::publish('ticks', 4);
    foreach ($later as $tick) {
        echo "a new subscription gets only tick $tick\n";
        break;
    }
});
