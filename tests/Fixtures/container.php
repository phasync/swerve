<?php

/*
 * Swerve::container() across workers, for tests/ContainerTest.php: the service "slot" has a
 * limit of 1. /hold?ms= takes it, says its pid and when it got it, and keeps it ms
 * milliseconds; /take takes it and lets go at once.
 */

use Swerve\Swerve;

Swerve::container()->add('slot', static fn () => new stdClass(), 1);

while (Swerve::accept(null, static function (): void {
    \parse_str($_SERVER['QUERY_STRING'], $query);
    $slot = Swerve::container()->get('slot');
    echo \getmypid() . ' ' . \microtime(true) . "\n";
    \flush();
    if ('/hold' === \parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH)) {
        phasync::sleep($query['ms'] / 1000);
    }
    echo \microtime(true);
})) {
}
