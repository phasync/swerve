<?php

/*
 * Touches Swerve::cache() and Swerve::publish() while swerve.php loads: directly, and from
 * coroutines started here that have not waited yet. /report answers what each of them saw.
 */

use Swerve\ClientRequest;
use Swerve\Swerve;

$report = new ArrayObject();

$attempt = static function (string $name, Closure $fn) use ($report): void {
    try {
        $fn();
        $report[$name] = 'ok';
    } catch (Throwable $e) {
        $report[$name] = $e::class;
    }
};

phasync::go(static function () use ($attempt) {
    $attempt('go cache', static function () {
        Swerve::cache()->set('early', 'from go');
        if ('from go' !== Swerve::cache()->get('early')) {
            throw new RuntimeException('lost');
        }
    });
    $attempt('go publish', static fn () => Swerve::publish('early', 'x'));
});
phasync::service(static function () use ($attempt) {
    $attempt('service cache', static fn () => Swerve::cache()->get('early'));
});
$attempt('load', static fn () => Swerve::cache()->get('early'));

while (Swerve::accept(null, function () use ($report) {
    Swerve::upgrade(static function (ClientRequest $r) use ($report): void {
        for ($waited = 0; \count($report) < 4 && $waited < 200; ++$waited) {
            phasync::sleep(0.01); // the coroutines may still wait for the worker to serve
        }
        $copy = $report->getArrayCopy();
        \ksort($copy);
        $body = \json_encode($copy);
        $r->sendResponseHeaders(200, ['Content-Length' => (string) \strlen($body)]);
        $r->write($body);
    });
})) {
}
