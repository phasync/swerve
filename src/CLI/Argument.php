<?php

namespace Swerve\CLI;

use InvalidArgumentException;

final class Argument
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $default='',
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
