<?php

namespace Swerve\Util;

use phasync\Context\SwitchAwareInterface;

/**
 * A request's context, once Swerve::onRequestSwitch() has registered something: resume() and
 * suspend() forward to {@see RequestSwitch}, which runs the registered closures with this
 * context's request. See {@see \Swerve\Http\HttpConnection}, which enters one of these eagerly
 * instead of the plain, lazy {@see LoggingContext} only once something is registered.
 *
 * @internal
 */
final class SwitchAwareLoggingContext extends LoggingContext implements SwitchAwareInterface
{
    /** A coroutine of this context's request runs next, after a different request's ran. */
    public function resume(): void
    {
        RequestSwitch::resume($this->request);
    }

    /** A coroutine of a different request runs next, after this one's. */
    public function suspend(): void
    {
        RequestSwitch::suspend($this->request);
    }
}
