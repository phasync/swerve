<?php

/*
 * Plain requests, served concurrently, for tests/ReloadTest.php: /hello, /pid, and
 * /sleep?ms=&id= (with &shielded=1, a stop can't cut it short).
 */

use Swerve\Swerve;

while (Swerve::accept(null, static function (): void {
    \parse_str($_SERVER['QUERY_STRING'], $query);
    $body = (string) match (\parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH)) {
        '/hello' => 'Hello',
        '/pid'   => \getmypid(),
        '/sleep' => (static function () use ($query): string {
            $sleep = static fn () => phasync::sleep($query['ms'] / 1000);
            isset($query['shielded']) ? phasync::shielded($sleep) : $sleep();

            return "slept {$query['id']}";
        })(),
    };
    \header('Content-Length: ' . \strlen($body));
    echo $body;
})) {
}
