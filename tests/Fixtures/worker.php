<?php

/*
 * A swerve FastCGI worker for the tests: serves tests/Fixtures/app.php on the address given as the
 * first argument, such as tcp://127.0.0.1:9123.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Psr\Log\NullLogger;
use Swerve\Dispatcher;
use Swerve\FastCGI\FastCGIServer;

phasync::run(function () use ($argv) {
    $logger = new NullLogger();
    $server = new FastCGIServer($argv[1], new Dispatcher(require __DIR__ . '/app.php', $logger), $logger);
    $server->listen();
    $server->run();
});
