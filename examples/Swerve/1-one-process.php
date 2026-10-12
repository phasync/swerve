<?php

use Swerve\Swerve;

// Without swerve's master process (a script, a test), Swerve works within this process
phasync::run(function () {
    $chat = Swerve::subscribe('chat');                 // subscribed from here on
    Swerve::publish('chat', ['user' => 'ann', 'text' => 'hi']);

    foreach ($chat as $message) {
        echo "$message->user: $message->text\n";
        break;
    }

    Swerve::cache()->set('visits', 1, 60);
    echo 'visits: ', Swerve::cache()->get('visits'), "\n";
});
