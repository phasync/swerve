<?php
namespace Swerve\Http\Message;

use Psr\Http\Message\StreamInterface;

/**
 * Describes a data stream.
 *
 * Typically, an instance will wrap a PHP stream; this interface provides
 * a wrapper around the most common operations, including serialization of
 * the entire stream to a string.
 */
class Stream implements StreamInterface {
    use StreamTrait;

    /**
     * Cast a value to a Stream instance.
     *
     * Convenience helper that accepts various types and ensures you get a StreamInterface:
     * - StreamInterface: returned as-is (no-op)
     * - string: written to php://temp stream
     * - Stringable: cast to string, written to php://temp stream
     * - resource (stream type): wrapped in Stream instance
     *
     * Use this in methods that accept "string|StreamInterface" to normalize the input:
     * ```php
     * public function withBody($body) {
     *     $this->body = Stream::cast($body);
     * }
     * ```
     *
     * @param mixed $source Value to cast to Stream
     * @return StreamInterface Stream instance (original if already StreamInterface, new Stream otherwise)
     * @throws \InvalidArgumentException If source cannot be cast to Stream
     */
    public static function cast(mixed $source): StreamInterface {
        // Already a stream? Return as-is
        if ($source instanceof StreamInterface) {
            return $source;
        }

        // String or Stringable? Write to temp stream
        if (\is_string($source) || $source instanceof \Stringable) {
            $fp = \fopen('php://temp', 'w+');
            \fwrite($fp, (string) $source);
            \rewind($fp);
            return new static($fp);
        }

        // Stream resource? Wrap it
        if (\is_resource($source)) {
            if (\get_resource_type($source) === 'stream') {
                return new static($source);
            }
            throw new \InvalidArgumentException("Resource must be of type 'stream', got: " . \get_resource_type($source));
        }

        // Reject everything else (arrays, objects, etc.)
        throw new \InvalidArgumentException(
            \get_debug_type($source) . " cannot be cast to StreamInterface. " .
            "Accepted types: StreamInterface, string, Stringable, or stream resource."
        );
    }

    /**
     * @param resource $stream The stream resource to represent
     */
    public function __construct($stream) {
        $this->StreamTrait($stream);
    }

    /**
     * @deprecated Use cast() instead
     */
    public static function create(mixed $source): static {
        return static::cast($source);
    }
}
