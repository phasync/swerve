<?php

use Swerve\SubscriberLagException;
use Swerve\Swerve;

phasync::run(function () {
    $subscription = Swerve::subscribe('prices', maxLag: 0.1);
    Swerve::publish('prices', 101.5);

    phasync::sleep(0.3);                      // busy elsewhere for longer than maxLag

    try {
        foreach ($subscription as $price) {
            echo "price $price\n";
        }
    } catch (SubscriberLagException) {
        echo "fell behind: disconnect, and let the client catch up from storage\n";
    }
});
