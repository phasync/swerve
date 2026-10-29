<?php

namespace Swerve;

/**
 * How long an instance from {@see Container::get()} is shared.
 *
 * @topic resource-management
 */
enum Lifetime
{
    /** One instance per request: every get() of the request, and of the coroutines it starts, gets the same one. */
    case Scoped;

    /** A new instance for every get(). */
    case Transient;

    /** One instance per worker, for its lifetime. */
    case Singleton;
}
