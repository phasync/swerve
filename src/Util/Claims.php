<?php

namespace Swerve\Util;

/**
 * The master's table of claims, see Swerve\Claim: key => who holds it, until when, and in which
 * worker. Kept apart from the cache's entries, which are evicted when memory runs short; a claim is
 * never evicted, only expires, is released, or has its worker drain or die.
 *
 * @internal
 */
final class Claims
{
    /** @var array<string, array{0: string, 1: int, 2: int}> key => token, expiry (monotonic ns), owner */
    private array $held = [];
    private int $sweepAt = 1024;

    /**
     * @param array{0: string, 1: string, 2?: string, 3?: float} $call claim|renew|release, key, token, seconds; or check, key
     * @param int                                                $owner the worker's inbox: whoever's process this is
     */
    public function apply(array $call, int $owner): bool
    {
        $op   = $call[0];
        $key  = $call[1];
        $now  = \hrtime(true);
        $held = $this->held[$key] ?? null;
        if ('check' === $op) {
            return null !== $held && $held[1] > $now;
        }
        $token = $call[2];
        if (null !== $held && $held[1] <= $now) {
            $held = null;
        }
        if (null !== $held && $held[0] !== $token) {
            return false;
        }
        if ('release' === $op) {
            unset($this->held[$key]);

            return null !== $held;
        }
        if ('renew' === $op && null === $held) {
            return false;
        }
        $this->held[$key] = [$token, $now + (int) ($call[3] * 1_000_000_000), $owner];
        if (\count($this->held) >= $this->sweepAt) {
            $this->held    = \array_filter($this->held, static fn (array $c) => $c[1] > $now);
            $this->sweepAt = \max(1024, 2 * \count($this->held));
        }

        return true;
    }

    /** The process in $owner drains or is gone: what it claimed is free. */
    public function release(int $owner): void
    {
        $this->held = \array_filter($this->held, static fn (array $c) => $c[2] !== $owner);
    }
}
