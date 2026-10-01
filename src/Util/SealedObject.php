<?php

namespace Swerve\Util;

/**
 * A decoded JSON object that nobody can change: what a subscriber of {@see Swerve::subscribe()} gets for a published object.
 *
 * The message is decoded once per worker and shared by all its subscribers, which are
 * coroutines; a plain `stdClass` would let one attach a property, or change one, for all the
 * others to see. Nested objects are SealedObjects too.
 *
 * ```php
 * foreach (Swerve::subscribe('game') as $message) {
 *     if ($message instanceof SealedObject && isset($message->end)) {   // a property
 *         break;
 *     }
 *     foreach ($message as $name => $value) { ... }                     // iterable
 * }
 * ```
 *
 * Reading a missing property triggers a warning and gives null. Assigning or unsetting throws a
 * `LogicException`. `json_encode()` gives the object back as it was published.
 *
 * @implements \IteratorAggregate<string, mixed>
 *
 * @see Swerve::publish
 * @see Swerve::subscribe
 */
final class SealedObject implements \IteratorAggregate, \JsonSerializable
{
    /** @var array<string, mixed> the values as seen through __get(): objects wrapped, arrays sealed */
    private array $sealed = [];

    /**
     * Wrap a decoded object; {@see SealedObject::seal()} does it for a whole decoded value.
     *
     * @param \stdClass $inner what `json_decode()` returned; must not be changed afterwards
     */
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

    /**
     * The property `$name`; an object in it is a SealedObject.
     *
     * Triggers a warning and gives null for a property that is not there.
     */
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

    /** Whether the property exists and is not null. */
    public function __isset(string $name): bool
    {
        return isset($this->inner->$name);
    }

    /**
     * Always throws: the message is shared by every subscriber.
     *
     * @throws \LogicException always
     */
    public function __set(string $name, mixed $value): never
    {
        throw new \LogicException("Can't set \$$name: the message is shared by every subscriber");
    }

    /**
     * Always throws: the message is shared by every subscriber.
     *
     * @throws \LogicException always
     */
    public function __unset(string $name): never
    {
        throw new \LogicException("Can't unset \$$name: the message is shared by every subscriber");
    }

    /**
     * The properties by name; objects in them are SealedObjects.
     *
     * @return \Generator<string, mixed>
     */
    public function getIterator(): \Generator
    {
        foreach ($this->inner as $name => $_) {
            yield $name => $this->__get((string) $name);
        }
    }

    /** A copy of the object as it was published, for `json_encode()`. */
    public function jsonSerialize(): \stdClass
    {
        // A copy: handing out the inner object would let a caller change it
        return \json_decode(\json_encode($this->inner, \JSON_PRESERVE_ZERO_FRACTION), false);
    }
}
