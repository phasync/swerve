<?php

namespace Swerve\CLI;

use InvalidArgumentException;

final class Flag implements ArgInterface
{
    public function __construct(
        public readonly string $short = '',
        public readonly string $long = '',
        public readonly string $description = '',
        public readonly bool $multiple = false,
    ) {
        if (strlen($short) > 1) {
            throw new InvalidArgumentException("Short flag must be 1 character at most");
        }
    }

    public function getValue(array $options): int|string|array|null{
        $count = 0;
        if (\array_key_exists($this->short, $options)) {
            $count += \is_array($options[$this->short]) ? count($options[$this->short]) : 1;
        }
        if (\array_key_exists($this->long, $options)) {
            $count += \is_array($options[$this->long]) ? count($options[$this->long]) : 1;
        }
        return $count;
    }

    public function isInvalid(array $options): ?string {
        $count = 0;
        if ($this->short !== '' && \array_key_exists($this->short, $options)) {
            if (\is_array($options[$this->short])) {
                $count += \count($options[$this->short]);
            } else {
                ++$count;
            }
        }
        if ($this->long !== '' && \array_key_exists($this->long, $options)) {
            if (\is_array($options[$this->long])) {
                $count += \count($options[$this->long]);
            } else {
                ++$count;
            }
        }
        if ($count > 1 && !$this->multiple) {
            return "Multiple flags not allowed for:"
                . ($this->short !== '' ? ' -' . $this->short : '')
                . ($this->long !== '' ? ' --' . $this->long : '');
        }
        return null;
    }

    public function getShort(): string {
        return $this->short;
    }

    public function getLong(): string {
        return $this->long;
    }
}
