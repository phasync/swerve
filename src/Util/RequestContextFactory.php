<?php

namespace Swerve\Util;

use phasync\Context\ContextFactoryInterface;
use Swerve\ClientRequest;
use Psr\Log\LoggerInterface;

/**
 * Creates the phasync context of a request when its code first asks for one (phasync::getContext(),
 * go(), finally()): a request that never does costs none. See HttpConnection, which uses this
 * only while Swerve::onRequestSwitch() has registered nothing: once something has, the request's
 * context is entered eagerly instead, as a {@see SwitchAwareLoggingContext} - not through this
 * lazy factory, which phasync's own lazy-context bookkeeping does not expect to be entered
 * reentrantly from inside a resume() it is still in the middle of calling (see RequestSwitch).
 *
 * @internal
 */
final class RequestContextFactory implements ContextFactoryInterface
{
    public function __construct(private readonly LoggerInterface $logger, private readonly ClientRequest $request)
    {
    }

    public function createContext(): LoggingContext
    {
        return new LoggingContext($this->logger, $this->request);
    }
}
