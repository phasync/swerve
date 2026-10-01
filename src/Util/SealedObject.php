<?php

namespace Swerve\Util;

/**
 * A decoded JSON object that nobody can change: what a subscriber of Swerve::subscribe() gets
 * for a published object. The message is decoded once per worker and shared by all its
 * subscribers, which are coroutines; a plain stdClass would let one attach a property, or change
 * one, for all the others to see.
 *
 *     $message->end            // a property; nested objects are SealedObjects too
 *     isset($message->end)
 *     foreach ($message as $name => $value) { ... }
 *
 * Reading a missing property is the warning stdClass gives. Assigning or unsetting throws.
 * json_encode() gives the object back as it was published.
 *
 * @implements \IteratorAggregate<string, mixed>
 */
final class SealedObject implements \IteratorAggregate, \JsonSerializable
{
    /** @var array<string, mixed> the values as seen through __get(): objects wrapped, arrays sealed */
    private array $sealed = [];

    public function __construct(private readonly \stdClass $inner)
    {
    }

    /**
     * What json_decode() (without assoc) returned, with its objects replaced by SealedObjects; the
     * objects inside one are wrapped when first read.
     */
    public static function seal(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            return new self($value);
        }
        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                if ($item instanceof \stdClass || \is_array($item)) {
                    $value[$key] = self::seal($item);
                }
            }
        }

        return $value;
    }

    public function __get(string $name): mixed
    {
        if (\array_key_exists($name, $this->sealed)) {
            return $this->sealed[$name];
        }
        if (!\property_exists($this->inner, $name)) {
            \trigger_error("Undefined property: " . self::class . "::\$$name", \E_USER_WARNING);

            return null;
        }

        return $this->sealed[$name] = self::seal($this->inner->$name);
    }

    public function __isset(string $name): bool
    {
        return isset($this->inner->$name);
    }

    public function __set(string $name, mixed $value): never
    {
        throw new \LogicException("Can't set \$$name: the message is shared by every subscriber");
    }

    public function __unset(string $name): never
    {
        throw new \LogicException("Can't unset \$$name: the message is shared by every subscriber");
    }

    public function getIterator(): \Generator
    {
        foreach ($this->inner as $name => $_) {
            yield $name => $this->__get((string) $name);
        }
    }

    public function jsonSerialize(): \stdClass
    {
        // A copy: handing out the inner object would let a caller change it
        return \json_decode(\json_encode($this->inner, \JSON_PRESERVE_ZERO_FRACTION), false);
    }
}
