<?php

namespace Swerve;

use phasync;

/**
 * A handle on a name that one worker at a time can hold across the whole server, from
 * Swerve::claim(). The handle claims nothing until acquire().
 *
 *     if ($claim = Swerve::claim('nightly-report')->acquire()) {
 *         ...
 *     }                                     // released when $claim goes out of scope
 *
 * The master decides, so two workers never hold the same name. A claim is held until it is
 * released, its handle is destroyed, or its worker drains or dies (a stalled worker is killed by
 * the watchdog): whoever acquires next takes over at once. Each handle has a token of its own, so
 * a stale handle can neither hold nor release what another holds. Names are a namespace of their
 * own, apart from the cache's keys. Without a master (served by something else than swerve's
 * workers) a name is held once at a time in the process.
 */
final class Claim
{
    /** Seconds between a waiting acquire()'s attempts. */
    private const POLL = 0.02;

    private readonly string $token;
    private bool $acquired = false;

    public function __construct(public readonly string $name)
    {
        if ('' === $name) {
            throw new \InvalidArgumentException('A claim needs a name');
        }
        $this->token = \bin2hex(\random_bytes(8));
    }

    /** Whether nobody holds the name now; one trip to the master, nothing is claimed. */
    public function available(): bool
    {
        return !Cache::instance()->call(['check', $this->name]);
    }

    /**
     * Take the name, trying every 20 ms for up to $timeout seconds when another holds it:
     * whoever tries first when it frees gets it, in no order. Returns this handle when it holds
     * the name, also if it did already; null when it could not.
     */
    public function acquire(float $timeout = 0.0): ?static
    {
        $deadline = \hrtime(true) / 1e9 + $timeout;
        // From here on the master may take the name: a coroutine cancelled while the call is in flight
        // never learns it did, so the destructor must release (harmless when it did not)
        $this->acquired = true;
        while (!Cache::instance()->call(['claim', $this->name, $this->token])) {
            $left = $deadline - \hrtime(true) / 1e9;
            if ($left <= 0) {
                $this->acquired = false;

                return null;
            }
            phasync::sleep(\min(self::POLL, $left));
        }

        return $this;
    }

    /** Whether this handle holds the name right now, as the master says: not released, and its worker not drained. */
    public function held(): bool
    {
        return Cache::instance()->call(['held', $this->name, $this->token]);
    }

    /** Give the claim up, so that another can take it. */
    public function release(): void
    {
        Cache::instance()->call(['release', $this->name, $this->token]);
        $this->acquired = false;
    }

    /** Releases without waiting for the master's reply: a destructor must not suspend. */
    public function __destruct()
    {
        if ($this->acquired) {
            Cache::instance()->send(['release', $this->name, $this->token]);
        }
    }
}
