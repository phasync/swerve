<?php

namespace Swerve\Util;

use Swerve\Container;

/**
 * One taken slot of a limited Container service: counted in APCu for the service (`<key>:n`) and
 * for this worker (`<key>:<pid>`, which the master subtracts should the worker die), and given
 * back when the instance holding it is garbage, which every worker waiting for a slot hears.
 *
 * @internal
 */
final class ContainerSlot
{
    private readonly int $pid;

    public function __construct(private readonly string $key)
    {
        $this->pid = \getmypid();
        self::increment("$key:{$this->pid}");
    }

    public function __destruct()
    {
        \apcu_dec("{$this->key}:n");
        \apcu_dec("{$this->key}:{$this->pid}");
        Container::released($this->key);
    }

    /** Add one to a count, which starts at 0 (also after APCu expunged it). */
    public static function increment(string $key): int
    {
        $n = \apcu_inc($key);
        if (false === $n) {
            \apcu_add($key, 0);
            $n = \apcu_inc($key);
        }

        return $n;
    }
}
