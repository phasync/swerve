<?php

namespace Swerve\CLI;

use InvalidArgumentException;

/**
 * A positional argument of the `swerve` command.
 *
 * @internal
 */
final class Argument
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        /** null: required */
        public readonly ?string $default = null,
        public readonly bool $multiple = false,
        public readonly ?\Closure $validator = null,
    ) {}

    public function isInvalid(string $value): ?string {
        if ($this->validator === null) {
            return null;
        }
        return ($this->validator)($value);
    }

}
