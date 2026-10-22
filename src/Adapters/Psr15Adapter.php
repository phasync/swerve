<?php

namespace Swerve\Adapters;

use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Adapter;
use Swerve\ClientRequest;
use Swerve\Mode;
use Swerve\Psr\Bridge;

/**
 * Runs a PSR-15 `RequestHandlerInterface`, in every mode.
 *
 * @internal
 */
final class Psr15Adapter implements Adapter
{
    public function canHandle(object $handler, Mode $mode): bool
    {
        return $handler instanceof RequestHandlerInterface;
    }

    public function handle(ClientRequest $request, object $handler): void
    {
        Bridge::serve($request, $handler->handle(...));
    }
}
