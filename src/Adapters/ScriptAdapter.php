<?php

namespace Swerve\Adapters;

use Swerve\Adapter;
use Swerve\ClientRequest;
use Swerve\Mode;

/**
 * Runs `function (): void` as a classic script, in every mode: it reads the superglobals and
 * `php://input`, and its `echo`, `header()` and `http_response_code()` are the response, as every
 * request in a worker runs inside `sapi()` (ext-phasync's, or phasync/hooked's without it).
 *
 * @internal
 */
final class ScriptAdapter implements Adapter
{
    public function canHandle(object $handler, Mode $mode): bool
    {
        if (!$handler instanceof \Closure || 0 !== (new \ReflectionFunction($handler))->getNumberOfRequiredParameters()) {
            return false;
        }
        if (\PHP_VERSION_ID < 80400 && !\Swerve\Util\Plug::isInstalled()) {
            throw new \LogicException('A function (): void handler needs PHP 8.4 with ext-phasync, for request_parse_body()');
        }

        return true;
    }

    public function handle(ClientRequest $request, object $handler): void
    {
        $handler();
    }
}
