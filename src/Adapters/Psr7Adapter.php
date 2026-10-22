<?php

namespace Swerve\Adapters;

use Psr\Http\Message\ServerRequestInterface;
use Swerve\Adapter;
use Swerve\ClientRequest;
use Swerve\Mode;
use Swerve\Psr\Bridge;

/**
 * Runs `function (ServerRequestInterface $request): ResponseInterface`, in every mode: also a
 * PSR-15 handler's `$app->handle(...)`.
 *
 * @internal
 */
final class Psr7Adapter implements Adapter
{
    public function canHandle(object $handler, Mode $mode): bool
    {
        return $handler instanceof \Closure && ServerRequestInterface::class === Signature::firstParameter($handler);
    }

    public function handle(ClientRequest $request, object $handler): void
    {
        Bridge::serve($request, $handler);
    }
}
