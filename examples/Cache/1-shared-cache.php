<?php

use Swerve\Swerve;

phasync::run(function () {
    $cache = Swerve::cache();                 // PSR-16: Psr\SimpleCache\CacheInterface

    $cache->set('greeting', 'hello', 60);     // 60 seconds
    $cache->set('config', ['debug' => false, 'level' => 3]);   // until evicted

    echo $cache->get('greeting'), "\n";
    var_dump($cache->get('config'));
    var_dump($cache->get('missing', 'a default'));

    $cache->set('greeting', 'gone', 0);       // a ttl of zero or less deletes
    var_dump($cache->has('greeting'));
});
