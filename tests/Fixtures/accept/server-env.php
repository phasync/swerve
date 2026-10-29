<?php
use Swerve\Swerve;

// $_SERVER in a request: the request's entries, not the worker's environment, which getenv() has
while (Swerve::accept(null, function () {
    echo isset($_SERVER['SWERVE_TEST_ENV']) ? 'in $_SERVER ' : '', getenv('SWERVE_TEST_ENV') ? 'getenv ' : '', $_SERVER['REQUEST_URI'];
})) {
}
