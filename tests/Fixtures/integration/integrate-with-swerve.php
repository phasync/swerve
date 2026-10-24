<?php

use Swerve\Swerve;

Swerve::ini(['memory_limit' => '1G']);
Swerve::onWorkerStart(static function () {
    // Say so where the test can see it, per process: a worker's own `$GLOBALS` is its boot Scope's,
    // and a request runs in a Scope of its own (see tests/RequestScopeTest.php), so a global set
    // here is not what a request reads -- the callback running is what is being tested
    if ($dir = \getenv('SWERVE_TEST_DIR')) {
        \file_put_contents("$dir/worker-start-" . \getmypid(), 'yes');
    }
});
