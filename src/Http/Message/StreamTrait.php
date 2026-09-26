<?php
namespace Swerve\Http\Message;

use Psr\Http\Message\StreamInterface;

use function rewind, stream_get_meta_data, get_class, fstat, fclose, ftell,
    strpos, feof, fseek, stream_get_contents;

/**
 * Describes a data stream.
 *
 * Typically, an instance will wrap a PHP stream; this interface provides
 * a wrapper around the most common operations, including serialization of
 * the entire stream to a string.
 */
trait StreamTrait {
    protected mixed $stream = null;
    protected bool $active;
    protected int $offset = 0;

    /**
     * Configure the StreamTrait
     *
     * @param resource $stream The stream resource to represent
     * @param bool $active The underlying stream resource is being written to
     */
    protected function StreamTrait($stream) {
        if (!is_resource($stream)) {
            throw new \RuntimeException("Expecting a valid stream resource.");
        }
        $this->stream = $stream;
    }

    /**
     * Reads all data from the stream into a string, from the beginning to end.
     *
     * This method MUST attempt to seek to the beginning of the stream before
     * reading data and read the stream until the end is reached.
     *
     * Warning: This could attempt to load a large amount of data into memory.
     *
     * This method MUST NOT raise an exception in order to conform with PHP's
     * string casting operations.
     *
     * @see http://php.net/manual/en/language.oop5.magic.php#object.tostring
     * @return string
     */
    public function __toString() {
        try {
            $this->assertUsable();
            if ($this->isSeekable()) {
                fseek($this->stream, 0);
            }
            return stream_get_contents($this->stream);
        } catch (\Throwable $e) {
            return get_class($e).': '.$e->getMessage();
        }
    }

    /**
     * Returns the remaining contents in a string
     *
     * @return string
     * @throws \RuntimeException if unable to read.
     * @throws \RuntimeException if error occurs while reading.
     */
    public function getContents(): string {
        $this->assertUsable();
        $result = stream_get_contents($this->stream);
        if ($result === false) {
            throw new \RuntimeException("Unable to read from stream");
        }
        return $result;
    }


    /**
     * Closes the stream and any underlying resources.
     *
     * @return void
     */
    public function close(): void {
        if ($this->stream) {
            fclose($this->stream);
        }
    }

    /**
     * Separates any underlying resources from the stream.
     *
     * After the stream has been detached, the stream is in an unusable state.
     *
     * @return resource|null Underlying PHP stream, if any
     */
    public function detach() {
        $stream = $this->stream;
        $this->stream = null;
        return $stream;
    }

    /**
     * Get the size of the stream if known.
     *
     * @return int|null Returns the size in bytes if known, or null if unknown.
     */
    public function getSize(): ?int {
        if (!$this->stream) {
            return null;
        }
        $stat = fstat($this->stream);
        if ($stat === false) {
            return null;
        }
        return $stat['size'] ?? null;
    }

    /**
     * Returns the current position of the file read/write pointer
     *
     * @return int Position of the file pointer
     * @throws \RuntimeException on error.
     */
    public function tell(): int {
        $this->assertUsable();
        if (!$this->stream || false === ($offset = ftell($this->stream))) {
            throw new \RuntimeException("Stream is in unusable state");
        }
        return $offset;
    }

    /**
     * Returns true if the stream is at the end of the stream.
     *
     * @return bool
     */
    public function eof(): bool {
        if (!$this->stream || feof($this->stream)) {
            return true;
        }
        return false;
    }

    /**
     * Returns whether or not the stream is seekable.
     *
     * @return bool
     */
    public function isSeekable(): bool {
        if (!$this->stream) {
            return false;
        }
        $meta = stream_get_meta_data($this->stream);
        return $meta['seekable'];
    }

    /**
     * Seek to a position in the stream.
     *
     * @see http://www.php.net/manual/en/function.fseek.php
     * @param int $offset Stream offset
     * @param int $whence Specifies how the cursor position will be calculated
     *     based on the seek offset. Valid values are identical to the built-in
     *     PHP $whence values for `fseek()`.  SEEK_SET: Set position equal to
     *     offset bytes SEEK_CUR: Set position to current location plus offset
     *     SEEK_END: Set position to end-of-stream plus offset.
     * @throws \RuntimeException on failure.
     */
    public function seek($offset, $whence = \SEEK_SET): void {
        $this->assertUsable();
        if (-1 === fseek($this->stream, $offset, $whence)) {
            throw new \RuntimeException("Unable to seek in stream");
        }
    }

    /**
     * Seek to the beginning of the stream.
     *
     * If the stream is not seekable, this method will raise an exception;
     * otherwise, it will perform a seek(0).
     *
     * @see seek()
     * @see http://www.php.net/manual/en/function.fseek.php
     * @throws \RuntimeException on failure.
     */
    public function rewind(): void {
        $this->assertUsable();
        if (!rewind($this->stream)) {
            throw new \RuntimeException("Unable to rewind the stream");
        }
    }

    /**
     * Returns whether or not the stream is writable.
     *
     * @return bool
     */
    public function isWritable(): bool {
        if (!$this->stream) {
            return false;
        }
        $meta = stream_get_meta_data($this->stream);
        return strpos($meta['mode'], 'r') === false || strpos($meta['mode'], '+') !== false;
    }

    /**
     * Write data to the stream.
     *
     * @param string $string The string that is to be written.
     * @return int Returns the number of bytes written to the stream.
     * @throws \RuntimeException on failure.
     */
    public function write($string): int {
        $this->assertUsable();
        $res = fwrite($this->stream, $string);
        if ($res === false) {
            throw new \RuntimeException("Unable to write to stream");
        }
        return $res;
    }

    /**
     * Returns whether or not the stream is readable.
     *
     * @return bool
     */
    public function isReadable(): bool {
        if (!$this->stream) {
            return false;
        }
        $meta = stream_get_meta_data($this->stream);
        return strpos($meta['mode'], 'r') !== false || strpos($meta['mode'], '+') !== false;
    }

    /**
     * Read data from the stream.
     *
     * @param int $length Read up to $length bytes from the object and return
     *     them. Fewer than $length bytes may be returned if underlying stream
     *     call returns fewer bytes.
     * @return string Returns the data read from the stream, or an empty string
     *     if no bytes are available.
     * @throws \RuntimeException if an error occurs.
     */
    public function read(int $length): string {
        $this->assertUsable();
        $res = fread($this->stream, $length);
        if ($res === false) {
            throw new \RuntimeException("Unable to read from stream");
        }
        return $res;
    }

    /**
     * Get stream metadata as an associative array or retrieve a specific key.
     *
     * The keys returned are identical to the keys returned from PHP's
     * stream_get_meta_data() function.
     *
     * @see http://php.net/manual/en/function.stream-get-meta-data.php
     * @param string $key Specific metadata to retrieve.
     * @return array|mixed|null Returns an associative array if no key is
     *     provided. Returns a specific key value if a key is provided and the
     *     value is found, or null if the key is not found.
     */
    public function getMetadata($key = null) {
        if (!$this->stream) {
            return null;
        }
        $meta = stream_get_meta_data($this->stream);
        if ($key !== null) {
            return $meta[$key] ?? null;
        }
        return $meta;
    }

    protected function assertUsable(): void {
        if (!$this->stream) {
            throw new \RuntimeException("Stream is in an unusable state");
        }
    }
}
