<?php

namespace Swerve\Adapters;

/**
 * @internal
 */
final class Signature
{
    /**
     * The class a closure's first parameter is declared as, when it is the only required one and
     * declared as one class or interface; null otherwise.
     */
    public static function firstParameter(\Closure $closure): ?string
    {
        $f = new \ReflectionFunction($closure);
        if ($f->getNumberOfRequiredParameters() > 1 || [] === $f->getParameters()) {
            return null;
        }
        $type = $f->getParameters()[0]->getType();

        return $type instanceof \ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
    }
}
