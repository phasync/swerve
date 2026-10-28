<?php

/*
 * The swerve.php of `swerve -S [dir] [router.php]`: the files of a directory, its PHP files run as
 * php -S runs them, and every other request to the router; without one, a 404. One request per
 * run, as under php-fpm: a classic script's top-level variables are globals, which two requests
 * at once would share, and each request starts over clean.
 */

use Swerve\Swerve;

phasync::await(Swerve::accept(\getenv('SWERVE_DIR'), \getenv('SWERVE_ROUTER') ?: null));
