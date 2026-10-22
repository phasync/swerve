<?php

/*
 * The router script of every `php -S` process {@see Swerve\Util\Classic\BuiltinRunner} starts:
 * not the Classic application's own router (Swerve's own `Swerve\Util\Router` already chose the
 * script to run before the request ever reaches here), but a bootstrap that makes `$_SERVER`
 * match what nginx + PHP-FPM would give the chosen script, as closely as `php -S` allows, then
 * runs it as the entry script.
 *
 * The chosen script, its SCRIPT_NAME and PATH_INFO, the public root and the client's identity
 * travel as request headers only BuiltinRunner sets (any client-sent copies of the same names
 * are stripped before they reach here, see BuiltinRunner::STRIP_REQUEST): this file reads them
 * out of $_SERVER's `HTTP_X_SWERVE_*` entries and removes them, so the application never sees
 * them.
 */

$script     = $_SERVER['HTTP_X_SWERVE_SCRIPT'];
$scriptName = $_SERVER['HTTP_X_SWERVE_SCRIPT_NAME'];
$pathInfo   = $_SERVER['HTTP_X_SWERVE_PATH_INFO'] ?? null;
$host       = $_SERVER['HTTP_X_SWERVE_HOST'];

$_SERVER['SCRIPT_FILENAME'] = $script;
$_SERVER['SCRIPT_NAME']     = $scriptName;
$_SERVER['PHP_SELF']        = $scriptName . $pathInfo;
$_SERVER['DOCUMENT_ROOT']   = $_SERVER['HTTP_X_SWERVE_DOCUMENT_ROOT'];
$_SERVER['REMOTE_ADDR']     = $_SERVER['HTTP_X_SWERVE_REMOTE_ADDR'];
$_SERVER['SERVER_PORT']     = $_SERVER['HTTP_X_SWERVE_SERVER_PORT'];
$_SERVER['HTTP_HOST']       = $host;
$_SERVER['SERVER_NAME']     = \strstr($host . ':', ':', true);
// php -S makes up PATH_INFO for a router from the request's path: only Swerve's counts
if (null !== $pathInfo) {
    $_SERVER['PATH_INFO'] = $pathInfo;
} else {
    unset($_SERVER['PATH_INFO'], $_SERVER['PATH_TRANSLATED']);
}
if (isset($_SERVER['HTTP_X_SWERVE_HTTPS'])) {
    $_SERVER['HTTPS'] = $_SERVER['HTTP_X_SWERVE_HTTPS'];
}
unset(
    $_SERVER['HTTP_X_SWERVE_SCRIPT'],
    $_SERVER['HTTP_X_SWERVE_SCRIPT_NAME'],
    $_SERVER['HTTP_X_SWERVE_PATH_INFO'],
    $_SERVER['HTTP_X_SWERVE_DOCUMENT_ROOT'],
    $_SERVER['HTTP_X_SWERVE_REMOTE_ADDR'],
    $_SERVER['HTTP_X_SWERVE_HTTPS'],
    $_SERVER['HTTP_X_SWERVE_SERVER_PORT'],
    $_SERVER['HTTP_X_SWERVE_HOST'],
);

\chdir(\dirname($script));

return require $script;
