<?php

/*
 * Touches Swerve::cache() and Swerve::publish() while swerve.php loads: directly, and from
 * coroutines started here that have not waited yet. /report answers what each of them saw.
 */

use phasync\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
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

return new class($report) implements RequestHandlerInterface {
    public function __construct(private ArrayObject $report)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        for ($waited = 0; \count($this->report) < 5 && $waited < 200; ++$waited) {
            phasync::sleep(0.01); // the coroutines may still wait for the worker to serve
        }
        $report = $this->report->getArrayCopy();
        \ksort($report);

        return new Response(200, [], \json_encode($report));
    }
};
