<?php

use Swerve\Swerve;

phasync::run(function () {
    $cache = Swerve::cache();

    $cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3], new DateInterval('PT5M'));   // one ttl for all

    var_export($cache->getMultiple(['a', 'b', 'x'], 'none'));
    echo "\n";
});
