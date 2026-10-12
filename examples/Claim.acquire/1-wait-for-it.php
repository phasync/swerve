<?php

use Swerve\Swerve;

phasync::run(function () {
    phasync::go(function () {
        $claim = Swerve::claim('import')->acquire();
        echo "first: importing\n";
        phasync::sleep(0.2);
        echo "first: done\n";
    });                                      // released as $claim goes

    phasync::go(function () {
        echo "second: waiting\n";
        if ($claim = Swerve::claim('import')->acquire(timeout: 5.0)) {   // tries every 20 ms
            echo "second: importing\n";
        }
    });
});
