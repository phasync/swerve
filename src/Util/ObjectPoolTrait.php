<?php

namespace Swerve\Util;

/**
 * Reuse of objects made per request, as phasync pools its own (phasync\Internal\ObjectPoolTrait,
 * which is internal to phasync): a class takes an instance with popInstance() before it
 * constructs one, and returnToPool() resets it and calls pushInstance().
 *
 * @internal
 */
trait ObjectPoolTrait
{
    private static array $pool        = [];
    private static int $instanceCount = 0;

    protected static function popInstance(): ?static
    {
        if (0 === self::$instanceCount) {
            return null;
        }
        $instance = self::$pool[--self::$instanceCount];
        // The pool must not keep referencing an instance that is in use. Otherwise its
        // destructor does not run when the last real reference is dropped.
        unset(self::$pool[self::$instanceCount]);

        return $instance;
    }

    protected function pushInstance(): void
    {
        self::$pool[self::$instanceCount++] = $this;
    }

    abstract public function returnToPool(): void;
}
