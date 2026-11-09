<?php

/*
 * The project's Composer class loader, made as vendor/autoload.php makes it, without including
 * that file: the application's own `$loader = require_once 'vendor/autoload.php'` then runs it and
 * gets the loader, as under php-fpm (Nextcloud keeps it; symfony/runtime returns early when the
 * file was included before). Included inside a function: its variables are not globals.
 */

$autoload = \getenv('SWERVE_AUTOLOAD');
if (1 !== \preg_match('/\bComposerAutoloaderInit\w+/', (string) \file_get_contents($autoload), $init)) {
    throw new \RuntimeException("swerve loads the class loader as $autoload does, but found no ComposerAutoloaderInit class in it: is it Composer's?");
}
require_once \dirname($autoload) . '/composer/autoload_real.php';
$init[0]::getLoader();
