<?php

namespace Swerve\Util;

/**
 * An object that can be returned to a pool for reuse.
 *
 * @internal
 */
interface ObjectPoolInterface
{
    public function returnToPool(): void;
}
