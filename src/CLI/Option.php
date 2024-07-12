<?php

namespace Swerve\CLI;

use InvalidArgumentException;

final class Option implements ArgInterface
{
    public function __construct(
        public readonly string $short = '',
        public readonly string $long = '',
        public readonly string $description = '',
        public readonly string $default = '',
        public readonly bool $required = false,
        public readonly ?\Closure $validator = null,
        public readonly string $placeholder = 'value',
        public readonly bool $multiple = false,
    ) {
        if (strlen($short) > 1) {
            throw new InvalidArgumentException("Short option must be 1 character at most");
        }
        if ($required && $default !== '') {
            throw new InvalidArgumentException("Can't have a default value for a required option");
        }
    }

    public function getValue(array $options): int|string|array|null {
        if ($this->multiple) {
            $values = [];
            if (\array_key_exists($this->short, $options)) {
                if (\is_array($options[$this->short])) {
                    foreach ($options[$this->short] as $v) {
                        $values[] = $v;
                    }
                } else {
                    $values[] = $options[$this->short];
                }
            }
            if (\array_key_exists($this->long, $options)) {
                if (\is_array($options[$this->long])) {
                    foreach ($options[$this->long] as $v) {
                        $values[] = $v;
                    }
                } else {
                    $values[] = $options[$this->long];
                }
            }
            if ($values === [] && $this->default !== '') {
                return [ $this->default ];
            }
            return $values;
        } else {
            return $options[$this->short] ?? $options[$this->long] ?? $this->default;
        }
    }

    public function isDefault(array $options): bool {
        if (\array_key_exists($this->short, $options)) {
            return false;
        }
        if (\array_key_exists($this->long, $options)) {
            return false;
        }
        return true;
    }

    public function isInvalid(array $options): ?string {
        $vals = [];

        if ($this->short !== '' && \array_key_exists($this->short, $options)) {
            if (\is_array($options[$this->short])) {
                foreach ($options[$this->short] as $v) {
                    if ($v === false) {
                        return "Value required for option: -{$this->short} <{$this->placeholder}>";
                    }
                    if ($this->validator !== null) {
                        $valid = ($this->validator)($v);
                        if ($valid !== null) {
                            return "Illegal value for option: -{$this->short}: $valid";
                        }
                    }
                    $vals[] = $v;
                }
            } else {
                if ($options[$this->short] === false) {
                    return "Value required for option: -{$this->short} <{$this->placeholder}>";
                }
                if ($this->validator !== null) {
                    $valid = ($this->validator)($options[$this->short]);
                    if ($valid !== null) {
                        return "Illegal value for option: -{$this->short}: $valid";
                    }
                }
                $vals[] = $options[$this->short];
            }
        }
        if ($this->long !== '' && \array_key_exists($this->long, $options)) {
            if (\is_array($options[$this->long])) {
                foreach ($options[$this->long] as $v) {
                    if ($v === false) {
                        return "Value required for option: --{$this->long}=<{$this->placeholder}>";
                    }
                    if ($this->validator !== null) {
                        $valid = ($this->validator)($v);
                        if ($valid !== null) {
                            return "Illegal value for option: --{$this->long}: $valid";
                        }
                    }
                    $vals[] = $v;
                }
            } else {
                if ($options[$this->long] === false) {
                    return "Value required for option: --{$this->long}=<{$this->placeholder}>";
                }
                if ($this->validator !== null) {
                    $valid = ($this->validator)($options[$this->long]);
                    if ($valid !== null) {
                        return "Illegal value for option: --{$this->long}: $valid";
                    }
                }
                $vals[] = $options[$this->long];
            }
        }
        if ($vals === [] && $this->required) {
            return "Value required for option:"
                . ($this->short !== '' ? ' -' . $this->short : '')
                . ($this->long !== '' ? ' --' . $this->long : '')
                . '<' . $this->placeholder . '>';
        }

        if (count($vals) > 1 && !$this->multiple) {
            return "Multiple values not permitted for:"
                . ($this->short !== '' ? ' -' . $this->short : '')
                . ($this->long !== '' ? ' --' . $this->long : '')
                . '<' . $this->placeholder . '>';
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
