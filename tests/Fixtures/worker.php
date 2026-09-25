<?php

/*
 * A swerve FastCGI worker for the tests: serves tests/Fixtures/app.php on the address given as the
 * first argument, such as tcp://127.0.0.1:9123.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Psr\Log\NullLogger;
use Swerve\FastCGI\FastCGIServer;
use Swerve\Runners\Psr15Runner;
use Swerve\Swerve;

$app = require __DIR__ . '/app.php';

$swerve = new Swerve(new NullLogger());
$swerve->add(new FastCGIServer($argv[1], new NullLogger()));
$swerve->run(new Psr15Runner($app));
