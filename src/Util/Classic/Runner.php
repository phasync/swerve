<?php

namespace Swerve\Util\Classic;

use Swerve\ClientRequest;

/**
 * Runs a Classic application's scripts, each request with PHP's own fresh request lifecycle, in
 * processes of its own: ext-phasync's request server ({@see ServerRunner}), or `php -S`
 * ({@see BuiltinRunner}) without the extension.
 *
 * @internal
 */
interface Runner
{
    /**
     * Run a script for the request, and send its response. The request's target is unchanged;
     * the script is as {@see \Swerve\Util\Router} resolved it.
     *
     * @param string      $script     SCRIPT_FILENAME
     * @param string      $scriptName SCRIPT_NAME
     * @param string|null $pathInfo   PATH_INFO, null: none
     */
    public function forward(ClientRequest $request, string $script, string $scriptName, ?string $pathInfo): void;
}
