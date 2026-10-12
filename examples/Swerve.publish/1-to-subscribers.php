<?php

use Swerve\Swerve;

phasync::run(function () {
    // Two subscribers, as two requests in a worker would have
    foreach (['ann', 'bob'] as $name) {
        $subscription = Swerve::subscribe('game');     // subscribed now, not at the foreach
        phasync::go(function () use ($subscription, $name) {
            foreach ($subscription as $message) {
                echo "$name got ", json_encode($message), "\n";
                if ('end' === $message) {
                    break;
                }
            }
        });
    }

    Swerve::publish('game', ['type' => 'score', 'player' => 7, 'points' => 10]);
    Swerve::publish('game', [7, 'kill']);
    Swerve::publish('game', 'end');
});
