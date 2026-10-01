<?php

namespace Swerve\CLI;

use phasync\Util\Console;

/**
 * The command line: options, flags and arguments, parsed as GNU tools do. Options and
 * arguments may come in any order; an option's value is attached (`--workers=4`, `-w4`) or
 * the next word (`--workers 4`, `-w 4`); flags may be grouped (`-vv`, `-qv`); `--` ends the
 * options.
 *
 * @internal
 */
final class Args
{
    /**
     * Options, flags and arguments, and section headings for --help (by an int key).
     *
     * @var array<string|int, Option|Flag|Argument|string>
     */
    private array $values = [];

    /** @var array{0: array<string, mixed>, 1: string[], 2: ?string}|null see parse() */
    private ?array $parsed = null;

    /**
     * @param string[]|null $argv the words after the command; $_SERVER['argv'] without its first by default
     */
    public function __construct(private ?array $argv = null)
    {
    }

    /** A heading in --help for the options added after it. */
    public function section(string $title): self
    {
        $this->values[] = $title;

        return $this;
    }

    public function add(string $name, ArgInterface|Argument $option): self
    {
        if (isset($this->values[$name])) {
            throw new \InvalidArgumentException("Option/flag `$name` already added");
        }
        foreach ($this->values as $valName => $v) {
            if ($option instanceof ArgInterface && $v instanceof ArgInterface) {
                if ('' !== $option->getShort() && $option->getShort() === $v->getShort()) {
                    throw new \InvalidArgumentException("Option/flag `$name`: -" . $option->getShort() . " already used for $valName.");
                }
                if ('' !== $option->getLong() && $option->getLong() === $v->getLong()) {
                    throw new \InvalidArgumentException("Option/flag `$name`: --" . $option->getLong() . " already used for $valName.");
                }
            }
        }
        $this->values[$name] = $option;

        return $this;
    }

    /**
     * The first thing wrong with the command line, or null.
     */
    public function isInvalid(): ?string
    {
        [$options, $words, $error] = $this->parse();
        if (null !== $error) {
            return $error;
        }
        $arguments = $this->getArguments();
        foreach (\array_values($arguments) as $i => $arg) {
            if (isset($words[$i])) {
                if (null !== ($e = $arg->isInvalid($words[$i]))) {
                    return $e;
                }
            } elseif ('' === $arg->default) {
                return 'Required argument: ' . $arg->name;
            }
        }
        if (\count($words) > \count($arguments)) {
            return 'Unknown argument: ' . $words[\count($arguments)];
        }
        foreach ($this->values as $value) {
            if ($value instanceof ArgInterface && null !== ($error = $value->isInvalid($options))) {
                return $error;
            }
        }

        return null;
    }

    public function __isset($name)
    {
        return isset($this->values[$name]);
    }

    public function __get(string $name): array|int|string
    {
        $value = $this->values[$name] ?? throw new \LogicException("Option/flag `$name` not defined");
        [$options, $words] = $this->parse();
        if ($value instanceof ArgInterface) {
            return $value->getValue($options);
        }
        $index = \array_search($name, \array_keys($this->getArguments()), true);

        return $words[$index] ?? $value->default;
    }

    public function isDefault(string $name): bool
    {
        $value = $this->values[$name] ?? throw new \LogicException("Option `$name` not defined");
        if ($value instanceof Option) {
            return $value->isDefault($this->parse()[0]);
        }
        if ($value instanceof Argument) {
            return $value->default === $this->$name;
        }
        throw new \LogicException('Flags have no default');
    }

    /**
     * The options, as getopt() would give them (a name to its value, false for a flag, a list
     * for one given more than once), the arguments, and the first error, if any.
     *
     * @return array{0: array<string, mixed>, 1: string[], 2: ?string}
     */
    private function parse(): array
    {
        if (null !== $this->parsed) {
            return $this->parsed;
        }
        $argv    = $this->argv ?? \array_slice($_SERVER['argv'], 1);
        $options = [];
        $words   = [];
        $error   = null;
        $set     = static function (string $name, string|false $value) use (&$options) {
            if (\array_key_exists($name, $options)) {
                $options[$name]   = (array) $options[$name];
                $options[$name][] = $value;
            } else {
                $options[$name] = $value;
            }
        };
        for ($i = 0; $i < \count($argv) && null === $error; ++$i) {
            $word = $argv[$i];
            if ('--' === $word) {
                \array_push($words, ...\array_slice($argv, $i + 1));
                break;
            }
            if (\str_starts_with($word, '--')) {
                [$long, $value] = \explode('=', \substr($word, 2), 2) + [1 => null];
                $arg            = $this->find(static fn (ArgInterface $a) => $a->getLong() === $long);
                if (null === $arg) {
                    $error = "Unknown option: --$long";
                } elseif ($arg instanceof Flag) {
                    null === $value ? $set($long, false) : $error = "--$long takes no value";
                } else {
                    $value ??= $argv[++$i] ?? null;
                    null === $value || '' === $value ? $error = "Value required for option: --$long=<{$arg->placeholder}>" : $set($long, $value);
                }
            } elseif (\strlen($word) > 1 && '-' === $word[0]) {
                for ($j = 1; $j < \strlen($word); ++$j) {
                    $arg = $this->find(static fn (ArgInterface $a) => $a->getShort() === $word[$j]);
                    if (null === $arg) {
                        $error = "Unknown flag: -$word[$j]" . (\strlen($word) > 2 ? " in $word" : '');
                        break;
                    }
                    if ($arg instanceof Option) {
                        $value = \substr($word, $j + 1);
                        $value = '' !== $value ? \ltrim($value, '=') : ($argv[++$i] ?? null);
                        null === $value || '' === $value ? $error = "Value required for option: -$word[$j] <{$arg->placeholder}>" : $set($word[$j], $value);
                        break;
                    }
                    $set($word[$j], false);
                }
            } else {
                $words[] = $word;
            }
        }

        return $this->parsed = [$options, $words, $error];
    }

    private function find(\Closure $match): ?ArgInterface
    {
        foreach ($this->values as $value) {
            if ($value instanceof ArgInterface && $match($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The options, flags and arguments, one per line under their section's heading, aligned as
     * Unix tools do.
     */
    public function getArgumentList(): string
    {
        $width = 0;
        foreach ($this->values as $value) {
            if (!\is_string($value)) {
                $width = \max($width, \strlen(self::makeArgList($value)));
            }
        }
        $output = [];
        foreach ($this->values as $value) {
            if (\is_string($value)) {
                $output[] = ($output ? "\n" : '') . '<!bold>' . Console::escape($value) . ':<!>';
                continue;
            }
            $description = $value->description . ($value instanceof Option && '' !== $value->default ? " (default: {$value->default})" : '');
            $output[]    = "  <!pad $width>" . Console::escape(self::makeArgList($value)) . '<!>  ' . Console::escape($description);
        }

        return \implode("\n", $output) . "\n";
    }

    /** `[options] [swerve.php]`. */
    public function getShortArgumentList(): string
    {
        $parts = ['[options]'];
        foreach ($this->getArguments() as $arg) {
            $parts[] = '' !== $arg->default ? "[$arg->name]" : "<$arg->name>";
        }

        return \implode(' ', $parts);
    }

    /**
     * @return array<string, Argument>
     */
    private function getArguments(): array
    {
        return \array_filter($this->values, static fn ($v) => $v instanceof Argument);
    }

    private static function makeArgList(ArgInterface|Argument $value): string
    {
        if ($value instanceof Argument) {
            return '' === $value->default ? "<$value->name>" : "[$value->name]";
        }
        $parts = [];
        if ('' !== $value->getShort()) {
            $parts[] = '-' . $value->getShort();
        }
        if ('' !== $value->getLong()) {
            $parts[] = '--' . $value->getLong();
        }
        $list = \implode(', ', $parts);
        if ($value instanceof Option) {
            $list .= ('' !== $value->getLong() ? '=' : ' ') . '<' . $value->placeholder . '>';
        }

        return $list;
    }
}
