<?php
namespace Swerve\Http\Message;

use Psr\Http\Message\StreamInterface;

/**
 * PSR-7 UploadedFileInterface implementation.
 *
 * Accepts either a file path (from $_FILES['tmp_name']) or a stream resource
 * (for non-SAPI / testing). Property names mirror the PSR-7 getter names.
 */
trait UploadedFileTrait {

    /** Stream resource when constructed from one; null for file-path sources */
    protected mixed $stream = null;

    /** Resolved file path when constructed from tmp_name; null for stream sources */
    protected ?string $path = null;

    /** Client-reported filename — $_FILES['name']. Do not trust. */
    protected ?string $clientFilename;

    /** Client-reported MIME type — $_FILES['type']. Do not trust. */
    protected ?string $clientMediaType;

    /** File size in bytes — $_FILES['size'] */
    protected ?int $size;

    /** Upload error code — one of UPLOAD_ERR_* constants */
    protected int $error;

    /** Override for is_uploaded_file() check — set true in tests */
    protected bool $isUploadedFile;

    /** Whether moveTo() has been called (PSR-7: must throw on second call) */
    protected bool $moved = false;

    /**
     * @param resource|string $source        Stream resource or file path (tmp_name)
     * @param ?string         $clientFilename Client-reported filename
     * @param ?string         $clientMediaType Client-reported MIME type
     * @param ?int            $size           File size in bytes
     * @param ?int            $error          Upload error code (UPLOAD_ERR_* constant)
     * @param bool            $isUploadedFile Override is_uploaded_file() for testing
     */
    protected function UploadedFileTrait(
        mixed $source,
        ?string $clientFilename = null,
        ?string $clientMediaType = null,
        ?int $size = null,
        ?int $error = null,
        bool $isUploadedFile = false,
    ): void {
        $this->error = $error ?? \UPLOAD_ERR_OK;
        $this->clientFilename = $clientFilename;
        $this->clientMediaType = $clientMediaType;
        $this->size = $size;
        $this->isUploadedFile = $isUploadedFile;

        if (is_resource($source)) {
            $this->stream = $source;
        } elseif ($this->error === \UPLOAD_ERR_OK && $source !== '') {
            // Only resolve path for successful uploads — errored uploads have no temp file
            $resolved = realpath($source);
            if ($resolved === false) {
                throw new \InvalidArgumentException("File path does not exist: '$source'");
            }
            $this->path = $resolved;
        }
    }

    /**
     * Retrieve a stream representing the uploaded file.
     *
     * @throws \RuntimeException If moveTo() was already called or file is unavailable
     */
    public function getStream(): StreamInterface {
        if ($this->moved) {
            throw new \RuntimeException('Cannot retrieve stream after moveTo() has been called');
        }
        $this->assertNoError();

        if ($this->stream !== null && is_resource($this->stream)) {
            return new Stream($this->stream);
        }
        if ($this->path !== null && file_exists($this->path)) {
            return new Stream(fopen($this->path, 'rb'));
        }
        throw new \RuntimeException('Uploaded file stream is unavailable');
    }

    /**
     * Move the uploaded file to a new location.
     *
     * Uses move_uploaded_file() for real SAPI uploads, rename() for
     * testing/CLI, and stream copying for stream-based sources.
     *
     * @throws \RuntimeException On second call, on error, or if move fails
     */
    public function moveTo(string $targetPath): void {
        if ($this->moved) {
            throw new \RuntimeException('Uploaded file has already been moved');
        }
        $this->assertNoError();

        if ($this->stream !== null) {
            $this->moveStreamTo($targetPath);
        } elseif ($this->path !== null) {
            $this->moveFileTo($targetPath);
        } else {
            throw new \RuntimeException('No upload source available');
        }

        $this->moved = true;
    }

    /**
     * Copy a stream source to the target path
     */
    private function moveStreamTo(string $targetPath): void {
        if (!rewind($this->stream)) {
            throw new \RuntimeException('Unable to rewind upload stream');
        }
        $fp = fopen($targetPath, 'xb');
        if (!$fp) {
            throw new \InvalidArgumentException("Unable to create file: '$targetPath'");
        }
        while (!feof($this->stream)) {
            $chunk = fread($this->stream, 8192);
            if ($chunk === false) {
                fclose($fp);
                unlink($targetPath);
                throw new \RuntimeException('Failed reading from upload stream');
            }
            if (fwrite($fp, $chunk) === false) {
                fclose($fp);
                unlink($targetPath);
                throw new \RuntimeException('Failed writing to target file');
            }
        }
        fclose($fp);
        fclose($this->stream);
    }

    /**
     * Move a file-path source to the target path.
     *
     * In SAPI: uses move_uploaded_file() for security (PSR-7 SHOULD).
     * In CLI or with isUploadedFile override: uses rename().
     */
    private function moveFileTo(string $targetPath): void {
        if (is_uploaded_file($this->path)) {
            // Real SAPI upload — secure move
            if (!move_uploaded_file($this->path, $targetPath)) {
                throw new \RuntimeException("move_uploaded_file() failed for: '$targetPath'");
            }
        } elseif ($this->isUploadedFile || \PHP_SAPI === 'cli') {
            // Test override or CLI environment — rename directly
            if (!rename($this->path, $targetPath)) {
                throw new \RuntimeException("Failed to move file to: '$targetPath'");
            }
        } else {
            throw new \RuntimeException('Source is not a valid uploaded file');
        }
    }

    /**
     * @return int|null File size in bytes, or null if unknown
     */
    public function getSize(): ?int {
        if ($this->size !== null) {
            return $this->size;
        }
        if ($this->stream !== null && is_resource($this->stream)) {
            $stat = fstat($this->stream);
            if ($stat && isset($stat['size'])) {
                return $this->size = $stat['size'];
            }
        }
        if ($this->path !== null && file_exists($this->path)) {
            return $this->size = filesize($this->path);
        }
        return null;
    }

    /**
     * @return int One of PHP's UPLOAD_ERR_* constants
     */
    public function getError(): int {
        return $this->error;
    }

    /**
     * @return string|null Client-reported filename, or null
     */
    public function getClientFilename(): ?string {
        return $this->clientFilename;
    }

    /**
     * @return string|null Client-reported media type, or null
     */
    public function getClientMediaType(): ?string {
        return $this->clientMediaType;
    }

    /**
     * @throws \RuntimeException If an upload error prevents the operation
     */
    protected function assertNoError(): void {
        if ($this->error !== \UPLOAD_ERR_OK) {
            throw new \RuntimeException("Upload error code {$this->error} prevented this operation");
        }
    }
}
