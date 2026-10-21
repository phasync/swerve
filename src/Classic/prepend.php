<?php

/*
 * auto_prepend_file for every `php -S` process of a ClassicPool (see ClassicProxy): the client's
 * identity, which ClassicProxy sends as headers only it sets (any client-sent copies are stripped
 * before the request reaches here), replaces the loopback values `php -S` itself would otherwise
 * report.
 */

if (isset($_SERVER['HTTP_X_SWERVE_REMOTE_ADDR'])) {
    $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_SWERVE_REMOTE_ADDR'];
}
if (isset($_SERVER['HTTP_X_SWERVE_HTTPS'])) {
    $_SERVER['HTTPS'] = $_SERVER['HTTP_X_SWERVE_HTTPS'];
}
if (isset($_SERVER['HTTP_X_SWERVE_SERVER_PORT'])) {
    $_SERVER['SERVER_PORT'] = $_SERVER['HTTP_X_SWERVE_SERVER_PORT'];
}
if (isset($_SERVER['HTTP_X_SWERVE_HOST'])) {
    $host                   = $_SERVER['HTTP_X_SWERVE_HOST'];
    $_SERVER['HTTP_HOST']   = $host;
    $_SERVER['SERVER_NAME'] = \strstr($host . ':', ':', true);
}
unset(
    $_SERVER['HTTP_X_SWERVE_REMOTE_ADDR'],
    $_SERVER['HTTP_X_SWERVE_HTTPS'],
    $_SERVER['HTTP_X_SWERVE_SERVER_PORT'],
    $_SERVER['HTTP_X_SWERVE_HOST'],
);
