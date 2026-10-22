<?php

namespace Swerve\Adapters;

use Swerve\Adapter;
use Swerve\ClientRequest;
use Swerve\Mode;

/**
 * Runs `function (): void` as a classic script, in every mode: it reads the superglobals and
 * `php://input`, and its `echo`, `header()` and `http_response_code()` are the response, as every
 * request in a worker runs inside ext-phasync's `sapi()`.
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
        if (!\function_exists('phasync\ext\sapi')) {
            throw new \LogicException('A function (): void handler needs ext-phasync, whose sapi() gives it its own output and headers');
        }
        if (\PHP_VERSION_ID < 80400) {
            throw new \LogicException('A function (): void handler needs PHP 8.4, for request_parse_body()');
        }

        return true;
    }

    public function handle(ClientRequest $request, object $handler): void
    {
        $handler();
    }
}
