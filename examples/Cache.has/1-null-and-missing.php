<?php

use Swerve\Swerve;

phasync::run(function () {
    $cache = Swerve::cache();
    $cache->set('nothing', null);

    var_dump($cache->has('nothing'));         // stored, with the value null
    var_dump($cache->has('never-set'));
    var_dump($cache->get('nothing', 'default'));
});
