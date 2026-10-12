<?php

use Swerve\Swerve;
use Swerve\Util\SealedObject;

phasync::run(function () {
    $subscription = Swerve::subscribe('news');

    Swerve::publish('news', 'a string stays a string');
    Swerve::publish('news', ['a', 'list']);
    Swerve::publish('news', ['title' => 'An object', 'tags' => ['x', 'y']]);

    foreach ($subscription as $message) {
        echo get_debug_type($message), ': ', json_encode($message), "\n";
        if ($message instanceof SealedObject) {
            echo "title: $message->title\n";
            break;
        }
    }
});
