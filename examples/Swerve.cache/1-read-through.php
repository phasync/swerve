<?php

use Swerve\Swerve;

function load_user(int $id): array
{
    echo "loading user $id from the database\n";

    return ['id' => $id, 'name' => 'Ann'];
}

phasync::run(function () {
    foreach ([1, 1, 1] as $id) {
        $user = Swerve::cache()->get("user.$id");
        if (null === $user) {
            $user = load_user($id);
            Swerve::cache()->set("user.$id", $user, 60);   // for every worker, 60 seconds
        }
        echo "hello, {$user['name']}\n";
    }
});
