<?php

/*
 * Touches Swerve::cache() and Swerve::publish() while swerve.php loads: directly, and from
 * coroutines started here that have not waited yet. /report answers what each of them saw.
 */

use phasync\Psr\Response;
use phasync\Psr\StringStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\OrderedChannel;
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
    $attempt('go ordered', static fn () => (new OrderedChannel('early'))->subscribe());
});
phasync::service(static function () use ($attempt) {
    $attempt('service cache', static fn () => Swerve::cache()->get('early'));
});
$attempt('load', static fn () => Swerve::cache()->get('early'));

while (true) {
    Swerve::accept(null, static function (ServerRequestInterface $r) use ($report): ResponseInterface {
        for ($waited = 0; \count($report) < 5 && $waited < 200; ++$waited) {
            phasync::sleep(0.01); // the coroutines may still wait for the worker to serve
        }
        $copy = $report->getArrayCopy();
        \ksort($copy);

        return new Response(200, [], new StringStream(\json_encode($copy)));
    });
}
