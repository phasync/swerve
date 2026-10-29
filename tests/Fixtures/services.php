<?php

/*
 * What serves the requests, first needed inside a request, for tests/ServicesTest.php: the
 * container's service "slot" (limit 1) is added by the first request that takes it; /hold?ms=
 * takes it, says its pid and when it got it, and keeps it ms milliseconds. /subscribe?n= waits
 * for n messages on "news" (1 by default); with &freed=<file> it writes the file once the
 * request's Scope is freed. /publish?m= sends one. /accept calls Swerve::accept() inside a request.
 */

use Swerve\Swerve;

while (Swerve::accept(null, static function (): void {
    static $freed = null;
    \parse_str($_SERVER['QUERY_STRING'], $query);
    switch (\parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH)) {
        case '/hold':
            if (!Swerve::container()->has('slot')) {
                Swerve::container()->add('slot', static fn () => new stdClass(), 1);
            }
            $slot = Swerve::container()->get('slot');
            echo \getmypid() . ' ' . \microtime(true) . "\n";
            \flush();
            phasync::sleep($query['ms'] / 1000);
            echo \microtime(true);

            return;
        case '/subscribe':
            if (isset($query['freed'])) {
                // Kept for as long as the request's Scope lives
                $freed ??= new WeakMap();
                $freed[Scope::getCurrent()] = new class ($query['freed']) {
                    public function __construct(private string $file)
                    {
                    }

                    public function __destruct()
                    {
                        \file_put_contents($this->file, 'freed');
                    }
                };
            }
            $n = (int) ($query['n'] ?? 1);
            foreach (Swerve::subscribe('news') as $message) {
                echo $message;
                if (0 === --$n) {
                    return;
                }
            }

            return;
        case '/publish':
            Swerve::publish('news', $query['m']);
            echo 'published';

            return;
        case '/accept':
            Swerve::accept(null, static fn () => null);

            return;
    }
    echo 'hello';
})) {
}
