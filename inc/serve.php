<?php

/*
 * The swerve.php of `swerve -S [dir] [router.php]`: the files of a directory, its PHP files run as
 * php -S runs them, and every other request to the router; without one, a 404.
 */

use Swerve\Swerve;

while (Swerve::accept(\getenv('SWERVE_DIR'), \getenv('SWERVE_ROUTER') ?: null)) {
}
