<?php

use Swerve\OrderedChannel;

phasync::run(function () {
    $alerts = new OrderedChannel('alerts');

    phasync::go(function () use ($alerts) {
        phasync::sleep(0.1);                 // after the foreach below has subscribed
        $alerts->write('disk 91% full');
        $alerts->write('disk 95% full');
    });

    // foreach subscribes with the defaults, at the foreach: what was written before is not seen
    foreach ($alerts as $n => $alert) {
        echo "$alert\n";
        if (1 === $n) {
            break;
        }
    }
});
