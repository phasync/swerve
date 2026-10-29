<?php

use Swerve\Swerve;

phasync::run(function () {
    $subscription = Swerve::subscribe('news');

    Swerve::publish('news', 'a string stays a string');
    Swerve::publish('news', "\x00\xFF bytes stay bytes");
    Swerve::publish('news', ['title' => 'An array', 'tags' => ['x', 'y']]);
    Swerve::publish('news', (object) ['note' => 'a stdClass, a copy for each subscriber']);

    foreach ($subscription as $message) {
        echo get_debug_type($message), ': ', json_encode($message, JSON_INVALID_UTF8_SUBSTITUTE), "\n";
        if ($message instanceof stdClass) {
            break;
        }
    }
});
