<?php // swerve.php: Laravel booted once per worker, reset between requests by Octane's listeners

use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\FrankenPhp\FrankenPhpClient;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Worker;
use Swerve\Swerve;

$client = new FrankenPhpClient();
$worker = new Worker(new ApplicationFactory(__DIR__), $client);
$worker->boot();

while ($request = Swerve::accept('public/', function () use ($worker, $client) {
    $_SERVER['LARAVEL_OCTANE'] = 1;
    [$request, $context] = $client->marshalRequest(new RequestContext());
    $worker->handle($request, $context);
})) {
    phasync::await($request);
}
