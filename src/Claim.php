<?php

namespace Swerve;

use phasync;

/**
 * A claim on a name, from Swerve::claim(): held by one worker at a time, across the whole server.
 *
 *     if ($claim = Swerve::claim('nightly-report', ttl: 10)) {
 *         while (...) { $claim->renew() or break; ... }
 *         $claim->release();
 *     }
 *
 * The master decides, so two workers never hold the same name. The claim lasts $ttl seconds
 * unless renewed, and ends at once when its worker drains or dies: whoever claims next takes
 * over without waiting for the TTL. A worker that stalls past the TTL loses the claim to
 * another; renew() tells it by returning false. Names are a namespace of their own, apart from
 * the cache's keys. claim() can wait up to a timeout for a name to come free, by trying every
 * 20 ms: whoever tries first when it frees gets it, in no order. Without a master (served by
 * something else than swerve's workers) a name is claimed once at a time in the process.
 */
final class Claim
{
    /** Seconds between a waiting claim's attempts. */
    private const POLL = 0.02;

    private function __construct(
        public readonly string $name,
        private readonly string $token,
        private readonly float $ttl,
    ) {
    }

    /** @internal see Swerve::claim() */
    public static function acquire(string $name, float $ttl, float $timeout): ?self
    {
        if ('' === $name || $ttl <= 0) {
            throw new \InvalidArgumentException('A claim needs a name and a TTL above 0');
        }
        $token    = \bin2hex(\random_bytes(8));
        $deadline = \hrtime(true) / 1e9 + $timeout;
        while (!Cache::instance()->call(['claim', $name, $token, $ttl])) {
            $left = $deadline - \hrtime(true) / 1e9;
            if ($left <= 0) {
                return null;
            }
            phasync::sleep(\min(self::POLL, $left));
        }

        return new self($name, $token, $ttl);
    }

    /** @internal see Swerve::claimed() */
    public static function held(string $name): bool
    {
        return Cache::instance()->call(['check', $name]);
    }

    /** Hold the claim for another TTL from now; false when it is lost: expired and taken, released, or its worker drained. */
    public function renew(): bool
    {
        return Cache::instance()->call(['renew', $this->name, $this->token, $this->ttl]);
    }

    /** Give the claim up, so that another can take it. */
    public function release(): void
    {
        Cache::instance()->call(['release', $this->name, $this->token]);
    }
}
