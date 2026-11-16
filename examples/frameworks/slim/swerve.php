<?php // swerve.php: the Slim app built once per worker; each request goes to $app->handle() as PSR-7

use Swerve\Swerve;

$app = require __DIR__ . '/app.php';

while ($request = Swerve::accept('public/', $app->handle(...))) {
    phasync::await($request);
}
