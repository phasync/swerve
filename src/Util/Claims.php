<?php

namespace Swerve\Util;

/**
 * The master's table of claims, see Swerve\Claim: key => who holds it, and in which worker. Kept
 * apart from the cache's entries, which are evicted when memory runs short; a claim is never
 * evicted, only released, or has its worker drain or die.
 *
 * @internal
 */
final class Claims
{
    /** @var array<string, array{0: string, 1: int}> key => token, owner */
    private array $held = [];

    /**
     * @param array{0: string, 1: string, 2?: string} $call claim|release|held, key, token; or check, key
     * @param int                                      $owner the worker's inbox: whoever's process this is
     */
    public function apply(array $call, int $owner): bool
    {
        [$op, $key] = $call;
        $held       = $this->held[$key] ?? null;
        if ('check' === $op) {
            return null !== $held;
        }
        $token = $call[2];
        if ('held' === $op) {
            return null !== $held && $held[0] === $token;
        }
        if (null !== $held && $held[0] !== $token) {
            return false;
        }
        if ('release' === $op) {
            unset($this->held[$key]);

            return null !== $held;
        }
        $this->held[$key] = [$token, $owner];

        return true;
    }

    /** The process in $owner drains or is gone: what it claimed is free. */
    public function release(int $owner): void
    {
        $this->held = \array_filter($this->held, static fn (array $c) => $c[1] !== $owner);
    }
}
