<?php

use Swerve\Swerve;

phasync::run(function () {
    $mine  = Swerve::claim('import');
    $yours = Swerve::claim('import');        // two handles on one name conflict, also in one process

    var_dump($mine->available());            // nobody holds it; nothing is claimed
    var_dump($mine->acquire() === $mine);    // the handle, now holding it
    var_dump($yours->acquire());             // null: held by another
    var_dump($mine->held(), $yours->held());

    $mine->release();
    var_dump($yours->acquire() === $yours);
});
