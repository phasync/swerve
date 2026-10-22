<?php

namespace Swerve\Adapters;

use Swerve\Adapter;
use Swerve\ClientRequest;
use Swerve\Mode;

/**
 * Runs `function (ClientRequest $request): void`, Swerve's native handler, in every mode.
 *
 * @internal
 */
final class ClientRequestAdapter implements Adapter
{
    public function canHandle(object $handler, Mode $mode): bool
    {
        return $handler instanceof \Closure && ClientRequest::class === Signature::firstParameter($handler);
    }

    public function handle(ClientRequest $request, object $handler): void
    {
        $handler($request);
    }
}
