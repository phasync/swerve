<?php

namespace Swerve\Util;

use phasync\Context\ContextFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates the phasync context of a request when its code first asks for one (phasync::getContext(),
 * go(), finally()): a request that never does costs none. See HttpConnection.
 *
 * @internal
 */
final class RequestContextFactory implements ContextFactoryInterface
{
    public function __construct(private readonly LoggerInterface $logger, private readonly ServerRequestInterface $request)
    {
    }

    public function createContext(): LoggingContext
    {
        return new LoggingContext($this->logger, $this->request);
    }
}
