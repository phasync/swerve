<?php

namespace Swerve\CLI;

final class Args
{
    /**
     * Options and flags.
     *
     * @var array<string, Option|Flag>
     */
    private array $values = [];

    private bool $hasArguments = false;

    /**
     * Validates all options and returns the first error string
     * if an error is found, or null if no errors are found.
     */
    public function isInvalid(): ?string
    {
        [ $shortOptions, $longOptions ] = $this->buildGetOpts();

        $restIndex = null;
        $options = getopt($shortOptions, $longOptions, $restIndex);

        $args = $this->getArguments();
        $argVals = [];
        foreach ($args as $arg) {
            if (isset($_SERVER['argv'][$restIndex])) {
                $val = $_SERVER['argv'][$restIndex++];
                if (null !== ($e = $arg->isInvalid($val))) {
                    return $e;
                }
                $argVals[] = $val;
            } elseif ($arg->default === '') {
                return 'Required argument: ' . $arg->name;
            } else {
                $argVals[] = $arg->default;
            }
        }

        $restIndex += count($args);

        // Check for unknown arguments using $rest_index
        if ($restIndex !== null && $restIndex < count($_SERVER['argv'])) {
            return 'Unknown argument: '.$_SERVER['argv'][$restIndex];
        }

        foreach ($_SERVER['argv'] as $argv) {
            if (\str_starts_with($argv, '--')) {
                $name = \substr($argv, 2);
                $parts = \explode("=", $name, 2);
                $found = false;
                foreach ($this->values as $v) {
                    if ($v instanceof Argument) {
                        continue;
                    }
                    if ($v->long === $parts[0]) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return 'Unknown option: --' . $parts[0];
                }
            } elseif (\str_starts_with($argv, '-')) {
                foreach ($this->values as $v) {
                    if ($v instanceof Argument) {
                        continue;
                    }
                    if ($v->short !== '' && \str_starts_with($argv, '-' . $v->short) && $v instanceof Option) {
                        continue 2;
                    }
                }
                $name = \substr($argv, 1);
                for ($i = 0; $i < \strlen($name); $i++) {
                    foreach ($this->values as $v) {
                        if ($v instanceof Argument) {
                            continue;
                        }
                        if ($v->short === $name[$i]) {
                            continue 2;
                        }
                    }
                    return "Unknown flag: -" . $name[$i] . (\strlen($name) > 1 ? " in -" . $name : '');
                }
            }
        }

        // Validate options
        foreach ($this->values as $name => $value) {
            if ($value instanceof ArgInterface) {
                $error = $value->isInvalid($options);
                if ($error !== null) {
                    return $error;
                }
            }
        }

        return null;
    }

    public function add(string $name, ArgInterface|Argument $option): self
    {
        if (isset($this->values[$name])) {
            throw new \InvalidArgumentException("Option/flag `$name` already added");
        }

        if ($option instanceof ArgInterface && $this->hasArguments) {
            throw new \InvalidArgumentException("Can't add option/flag after adding arguments");
        }

        foreach ($this->values as $valName => $v) {
            if ($option instanceof ArgInterface) {
                if ($option->short !== '' && $option->short === $v->short) {
                    throw new \InvalidArgumentException("Option/flag `$name`: -" . $option->short . " already used for $valName.");
                }
                if ($option->long !== '' && $option->long === $v->long) {
                    throw new \InvalidArgumentException("Option/flag `$name`: --" . $option->long . " already used for $valName.");
                }    
            }
        }

        if ($option instanceof Argument) {
            $this->hasArguments = true;
        }

        $this->values[$name] = $option;

        return $this;
    }

    public function __isset($name) {
        return isset($this->values[$name]);
    }

    public function __get(string $name): array|int|string
    {
        if (!isset($this->values[$name])) {
            throw new \LogicException("Option/flag `$name` not defined");
        }
        [ $short, $long ] = $this->buildGetOpts();
        $options = \getopt($short, $long, $restIndex);

        if ($this->values[$name] instanceof ArgInterface) {
            return $this->values[$name]->getValue($options);
        } elseif ($this->values[$name] instanceof Argument) {
            $args = $this->getArguments();
            $argVals = [];
            foreach ($args as $n => $arg) {
                if (isset($_SERVER['argv'][$restIndex])) {
                    $val = $_SERVER['argv'][$restIndex++];
                    if (null !== ($e = $arg->isInvalid($val))) {
                        return $e;
                    }
                    $argVals[$n] = $val;
                } else {
                    $argVals[$n] = $arg->default;
                }
            }
            return $argVals[$n];
        }
    }

    public function isDefault(string $name): bool {
        if (!isset($this->values[$name])) {
            throw new \LogicException("Option `$name` not defined");
        }
        $val = $this->values[$name];
        if ($val instanceof Option) {
            [ $short, $long ] = $this->buildGetOpts();
            $options = \getopt($short, $long, $restIndex);
            return $val->isDefault($options);
        } elseif ($val instanceof Argument) {
            return $val->default === $this->$name;
        }
        throw new \LogicException("Flags have no default");
    }

    /**
     * Returns a nicely aligned argument list similar to typical Unix applications.
     */
    public function getArgumentList(): string
    {
        $output = [];
        $maxLen = 0;

        // Calculate the maximum length of the option/flag strings
        foreach ($this->values as $value) {
            $maxLen = max($maxLen, \strlen(self::makeArgList($value)));
        }

        // Build the output lines with proper alignment
        foreach ($this->values as $value) {
            $name = self::makeArgList($value);
            $description = $value->description.($value instanceof Option && $value->default ? " (default: {$value->default})" : '');

            $output[] = sprintf('%-'.$maxLen.'s  %s', $name, $description);
        }

        return implode("\n", $output)."\n";
    }

    /**
     * Returns a short version of the argument list.
     */
    public function getShortArgumentList(): string
    {
        $flags = [];
        $requiredOptions = [];
        $optionalOptions = [];

        foreach ($this->values as $value) {
            if ($value instanceof Flag) {
                $flags[] = $value->short;
            } elseif ($value instanceof Option) {
                $placeholder = $value->placeholder ? ' <'.$value->placeholder.'>' : '';
                $optionStr = self::makeArgList($value);
                if ($value->required) {
                    $requiredOptions[] = $optionStr;
                } else {
                    $optionalOptions[] = "[$optionStr]";
                }
            }
        }

        $parts = [];
        if ($flags) {
            $parts[] = '[-'.implode('', $flags).']';
        }
        foreach ($requiredOptions as $o) {
            $parts[] = $o;
        }
        foreach ($optionalOptions as $o) {
            $parts[] = $o;
        }

        foreach ($this->getArguments() as $arg) {
            if ($arg->default !== '') {
                $parts[] = '[' . $arg->name . ']';
            } else {
                $parts[] = '<' . $arg->name . '>';
            }
        }

        return \implode(" ", $parts);
    }
    
    /**
     * 
     * @return Argument[] 
     */
    private function getArguments(): array {
        $result = [];
        foreach ($this->values as $n => $v) {
            if ($v instanceof Argument) {
                $result[$n] = $v;
            }
        }
        return $result;
    }

    private function buildGetOpts(): array {
        $shortOptions = '';
        $longOptions = [];
        foreach ($this->values as $val) {
            if ($val instanceof Option) {
                if ($val->short !== '') {
                    $shortOptions .= $val->short . ':' . ($val->required ? '' : ':');
                }
                if ($val->long !== '') {
                    $longOptions[] = $val->long . ':' . ($val->required ? '' : ':');
                }
            } elseif ($val instanceof Flag) {
                if ($val->short !== '') {
                    $shortOptions .= $val->short;
                }
                if ($val->long !== '') {
                    $longOptions[] = $val->long;
                }
            }
        }

        return [ $shortOptions, $longOptions ];

    }

    private static function makeArgList(ArgInterface|Argument $value): string {
        if ($value instanceof Argument) {
            if ($value->default === '') {
                return '<' . $value->name . '>';
            } else {
                return '[' . $value->name . ']';
            }
        }
        $hadLong = false;
        $parts = [];
        if ($value->getShort() !== '') {
            $parts[] = '-' . $value->getShort();
        }
        if ($value->getLong() !== '') {
            $hadLong = true;
            $parts[] = '--' . $value->getLong();
        }
        if ($value instanceof Option) {
            return \implode(",", $parts) . ($hadLong ? '=' : ' ') . '<' . $value->placeholder . '>';
        } else {
            return \implode(",", $parts);
        }
    }
}
