<?php

namespace Swerve\Http;

/**
 * Whether phasync-ext's virtualize() is there for Swerve::virtualize(): it needs phasync-ext
 * 0.5.0-alpha15 or later.
 *
 * @internal
 */
final class Virtual
{
    public static function available(): bool
    {
        return \function_exists('phasync\ext\virtualize') && \version_compare((string) \phpversion('phasync'), '0.5.0-alpha15', '>=');
    }
}
