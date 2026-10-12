<?php

use Swerve\CacheKeyException;
use Swerve\Swerve;

phasync::run(function () {
    foreach (['user.1', 'user:1', '', 'a/b'] as $key) {
        try {
            Swerve::cache()->get($key);
            echo "'$key' is a key\n";
        } catch (CacheKeyException $e) {
            echo "'$key' is not: ", $e->getMessage(), "\n";
        }
    }
});
