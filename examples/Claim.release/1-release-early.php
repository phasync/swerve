<?php

use Swerve\Swerve;

phasync::run(function () {
    $claim = Swerve::claim('leader');
    $claim->acquire();
    echo 'held: ', var_export($claim->held(), true), "\n";

    $claim->release();                       // give it up while the handle lives on
    echo 'held: ', var_export($claim->held(), true), "\n";
    echo 'available: ', var_export($claim->available(), true), "\n";

    $claim->release();                       // releasing what is not held does nothing
});
