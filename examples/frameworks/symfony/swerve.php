<?php // swerve.php: Symfony booted once per worker; the kernel resets its services between requests

use App\Kernel;
use Swerve\Swerve;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

(new Dotenv())->bootEnv(__DIR__ . '/.env');
$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);

while ($request = Swerve::accept('public/', function () use ($kernel) {
    $request  = Request::createFromGlobals();
    $response = $kernel->handle($request);
    $response->send();
    $kernel->terminate($request, $response);
})) {
    phasync::await($request);
}
