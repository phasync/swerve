<?php
namespace Swerve\Http\Message;

use Psr\Http\Message\UploadedFileInterface;

/**
 * PSR-7 UploadedFileInterface implementation.
 *
 * Constructed from $_FILES data (via HttpDispatcher) or manually for testing.
 */
class UploadedFile implements UploadedFileInterface {
    use UploadedFileTrait;

    /**
     * @param resource|string $source         Stream resource or file path ($_FILES['tmp_name'])
     * @param ?string         $clientFilename Client-reported filename ($_FILES['name'])
     * @param ?string         $clientMediaType Client-reported MIME type ($_FILES['type'])
     * @param ?int            $size           File size in bytes ($_FILES['size'])
     * @param ?int            $error          Upload error code ($_FILES['error'])
     * @param bool            $isUploadedFile Override is_uploaded_file() check for testing
     */
    public function __construct(
        mixed $source,
        ?string $clientFilename = null,
        ?string $clientMediaType = null,
        ?int $size = null,
        ?int $error = null,
        bool $isUploadedFile = false,
    ) {
        $this->UploadedFileTrait($source, $clientFilename, $clientMediaType, $size, $error, $isUploadedFile);
    }
}
