<?php

use Swerve\Swerve;

phasync::run(function () {
    $cache = Swerve::cache();
    $cache->setMultiple(['session.1' => 'ann', 'session.2' => 'bob', 'session.3' => 'cy']);

    $cache->delete('session.1');                          // in every worker
    $cache->deleteMultiple(['session.2', 'not-there']);    // true, also for a key not there

    var_export($cache->getMultiple(['session.1', 'session.2', 'session.3']));
    echo "\n";
});
