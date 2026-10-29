<?php

/*
 * php-fpm's and LiteSpeed's way to finish a response early, for the application's code in a
 * Swerve worker: Swerve::accept() loads this once per lifecycle. Neither exists under the CLI.
 */

use Swerve\Swerve;

if (!\function_exists('fastcgi_finish_request')) {
    function fastcgi_finish_request(): bool
    {
        return Swerve::finishRequest();
    }
}

if (!\function_exists('litespeed_finish_request')) {
    function litespeed_finish_request(): bool
    {
        return Swerve::finishRequest();
    }
}
