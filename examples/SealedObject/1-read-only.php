<?php

use Swerve\Swerve;

phasync::run(function () {
    $subscription = Swerve::subscribe('game');
    Swerve::publish('game', ['type' => 'move', 'player' => ['id' => 7, 'name' => 'ann'], 'to' => [3, 4]]);

    foreach ($subscription as $message) {
        echo $message->type, ' by ', $message->player->name, "\n";   // nested objects are sealed too
        var_dump(isset($message->end));

        foreach ($message as $name => $value) {
            echo "$name: ", get_debug_type($value), "\n";
        }

        try {
            $message->type = 'cheat';            // shared by every subscriber: refused
        } catch (LogicException $e) {
            echo get_class($e), "\n";
        }

        echo json_encode($message), "\n";       // as it was published
        break;
    }
});
