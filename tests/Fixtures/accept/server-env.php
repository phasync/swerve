<?php
use Swerve\Swerve;

// $_SERVER in a request: the worker's own entries (its environment) and the request's
while (Swerve::accept(null, function () {
    echo isset($_SERVER['SWERVE_TEST_ENV']) ? 'env ' : 'no env ', $_SERVER['REQUEST_URI'];
})) {
}
