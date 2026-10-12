<?php

use Swerve\Swerve;

phasync::run(function () {
    $cache = Swerve::cache();
    $cache->setMultiple(['a' => 1, 'b' => 2]);

    $cache->clear();                          // everything, in every worker

    var_export($cache->getMultiple(['a', 'b']));
    echo "\n";
});
