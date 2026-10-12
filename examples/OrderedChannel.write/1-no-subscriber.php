<?php

use Swerve\OrderedChannel;

phasync::run(function () {
    $events = new OrderedChannel('events');

    $events->write('lost');                  // a broadcast: nobody subscribes, nothing is kept

    $subscription = $events->subscribe();    // receives what is written from here on
    $events->write('seen');

    foreach ($subscription as $event) {
        echo "$event\n";
        break;
    }
});
