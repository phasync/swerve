<?php

namespace Swerve\Http;

use Psr\Http\Message\StreamInterface;
use phasync\Psr\StreamFactory;
use phasync\Psr\UploadedFile;
use Swerve\Swerve;

/**
 * The form data of a request, parsed when first asked for: what PHP puts in $_POST and $_FILES.
 *
 * PHP parses a request body only when its head says to: a POST whose Content-Type is
 * application/x-www-form-urlencoded or multipart/form-data, with enable_post_data_reading on.
 * Every other body (a PUT, a JSON POST) stays raw. Swerve follows the same rule, but lazily: the
 * body is read on the first getParsedBody() or getUploadedFiles(), so a handler that streams the
 * body itself, or never looks at it, costs nothing. Upgrade requests are never parsed.
 *
 * PHP's limits from php.ini apply as PHP applies them: post_max_size (the whole body; beyond it
 * the form is empty, with a warning), upload_max_filesize (a larger file gets
 * UPLOAD_ERR_INI_SIZE), max_file_uploads (further files are dropped), max_input_vars (further
 * fields are dropped), max_multipart_body_parts, and file_uploads. Uploaded files are streamed to
 * temporary files in upload_tmp_dir, and deleted when the request is gone unless moved.
 *
 * Names are decoded as PHP decodes them (`a[b][]`, dots and spaces in top-level names becoming
 * underscores), by handing them to parse_str(). One difference: parse_str() keeps exactly
 * max_input_vars fields, where PHP's own POST parser keeps one more.
 *
 * @internal see ServerRequest
 */
final class FormBody
{
    /** A multipart part's headers may not be larger than this, as PHP's. */
    private const MAX_PART_HEAD = 16384;

    private const READ_SIZE = 65536;

    private ?array $fields = null;
    private array $files = [];
    private ?StreamInterface $input = null;

    /** @var string[] temporary files of uploads, deleted when this is */
    private array $temporary = [];

    private function __construct(
        private readonly StreamInterface $body,
        private readonly ?string $boundary,
    ) {
    }

    /**
     * The form of a request with this method and Content-Type, or null when PHP would not parse
     * its body.
     */
    public static function for(string $method, string $contentType, StreamInterface $body): ?self
    {
        if ('POST' !== $method || !\ini_get('enable_post_data_reading')) {
            return null;
        }
        $type = \strtolower(\trim(\explode(';', $contentType, 2)[0]));
        if ('application/x-www-form-urlencoded' === $type) {
            return new self($body, null);
        }
        if ('multipart/form-data' === $type && \preg_match('/;\s*boundary=(?:"([^"]{1,70})"|([^\s;,]{1,70}))/i', $contentType, $m)) {
            return new self($body, '' !== $m[1] ? $m[1] : $m[2]);
        }

        return null;
    }

    /** $_POST: the fields. */
    public function fields(): array
    {
        $this->parse();

        return $this->fields;
    }

    /** $_FILES, as PSR-7 UploadedFileInterface objects. */
    public function files(): array
    {
        $this->parse();

        return $this->files;
    }

    /**
     * php://input: an url-encoded body's bytes, still readable after parsing; a multipart one's
     * are used up by the parser, as in PHP.
     */
    public function input(): StreamInterface
    {
        return $this->input ?? $this->body;
    }

    public function __destruct()
    {
        foreach ($this->temporary as $file) {
            if (\is_file($file)) {
                \unlink($file);
            }
        }
    }

    private function parse(): void
    {
        if (null !== $this->fields) {
            return;
        }
        if (0 !== $this->body->tell()) {
            throw new \LogicException('The request body was read before its form data was asked for; parse what you read yourself, or ask getParsedBody() before reading the body');
        }
        $this->fields = [];
        $max          = \ini_parse_quantity((string) \ini_get('post_max_size'));
        if (null === $this->boundary) {
            $raw = '';
            while ('' !== ($data = $this->body->read(self::READ_SIZE))) {
                $raw .= $data;
                if ($max > 0 && \strlen($raw) > $max) {
                    // As PHP: no form, but the body stays readable, in a stream that moves to disk
                    // once large
                    $input = \fopen('php://temp', 'w+');
                    \fwrite($input, $raw);
                    while ('' !== ($data = $this->body->read(self::READ_SIZE))) {
                        \fwrite($input, $data);
                    }
                    \rewind($input);
                    $this->tooLarge($max);
                    $this->input = StreamFactory::create($input);

                    return;
                }
            }
            $this->input = StreamFactory::create($raw);
            \parse_str($raw, $this->fields);

            return;
        }
        $this->input = StreamFactory::create('');
        $this->multipart($max);
    }

    /**
     * Stream the parts: fields into memory, files into temporary files, never holding more of a
     * file than a read plus the delimiter's length.
     */
    private function multipart(int $max): void
    {
        $delimiter = "\r\n--" . $this->boundary;
        $buffer    = "\r\n"; // the first delimiter has no CRLF before it
        $read      = 0;
        $more      = function () use (&$buffer, &$read, $max): bool {
            $data = $this->body->read(self::READ_SIZE);
            if ('' === $data) {
                return false;
            }
            $read += \strlen($data);
            if ($max > 0 && $read > $max) {
                throw new \LengthException();
            }
            $buffer .= $data;

            return true;
        };
        $fields     = [];
        $files      = [];
        $uploads    = 0;
        $inputVars  = (int) \ini_get('max_input_vars');
        $partsLimit = (int) \ini_get('max_multipart_body_parts');
        $maxFiles   = (int) \ini_get('max_file_uploads');
        $partsLimit = $partsLimit > 0 ? $partsLimit : $inputVars + $maxFiles;
        $fileMax    = \ini_parse_quantity((string) \ini_get('upload_max_filesize'));
        $parts      = 0;

        try {
            // The preamble, up to the first delimiter
            while (false === ($at = \strpos($buffer, $delimiter))) {
                $buffer = \substr($buffer, -\strlen($delimiter));
                if (!$more()) {
                    return;
                }
            }
            $buffer = \substr($buffer, $at + \strlen($delimiter));
            while (true) {
                // After a delimiter: "--" ends the body; anything else up to the end of the line
                // is ignored, as PHP does (RFC 2046 allows padding there), and a part starts
                while (\strlen($buffer) < 2 && $more()) {
                }
                if (\str_starts_with($buffer, '--')) {
                    break;
                }
                while (false === ($eol = \strpos($buffer, "\r\n"))) {
                    if (\strlen($buffer) > self::MAX_PART_HEAD || !$more()) {
                        return;
                    }
                }
                $buffer = \substr($buffer, $eol);
                if (++$parts > $partsLimit) {
                    Swerve::log()->warning('Multipart body parts limit exceeded ({limit}); the rest is ignored, see max_multipart_body_parts', ['limit' => $partsLimit]);
                    break;
                }
                // The part's head
                while (false === ($end = \strpos($buffer, "\r\n\r\n"))) {
                    if (\strlen($buffer) > self::MAX_PART_HEAD || !$more()) {
                        return;
                    }
                }
                [$name, $filename, $type, $disposition] = self::head(\substr($buffer, 2, $end - 2));
                $buffer                                  = \substr($buffer, $end + 4);
                if ($disposition && null === $name) {
                    // As PHP: a disposition without a name ends the parsing; what came before stands
                    Swerve::log()->warning('File Upload Mime headers garbled');
                    break;
                }

                // The part's content, up to the next delimiter
                $isFile = null !== $filename && null !== $name && \ini_get('file_uploads') && $uploads < $maxFiles;
                if (null !== $filename && null !== $name && $uploads >= $maxFiles && \ini_get('file_uploads')) {
                    Swerve::log()->warning('Maximum number of allowable file uploads ({max}) has been exceeded', ['max' => $maxFiles]);
                }
                $file    = $isFile ? $this->temporaryFile() : null;
                $size    = 0;
                $value   = '';
                $tooBig  = false;
                $content = static function (string $data) use (&$file, &$size, &$value, &$tooBig, $isFile, $fileMax): void {
                    $size += \strlen($data);
                    if (!$isFile) {
                        $value .= $data;
                    } elseif (!$tooBig) {
                        if ($fileMax > 0 && $size > $fileMax) {
                            $tooBig = true;
                        } else {
                            \fwrite($file, $data);
                        }
                    }
                };
                while (false === ($at = \strpos($buffer, $delimiter))) {
                    // Keep what could be the start of a delimiter split across reads
                    $keep = \strlen($delimiter) - 1;
                    if (\strlen($buffer) > $keep) {
                        $content(\substr($buffer, 0, -$keep));
                        $buffer = \substr($buffer, -$keep);
                    }
                    if (!$more()) {
                        return; // no closing delimiter: the part is incomplete, and dropped
                    }
                }
                $content(\substr($buffer, 0, $at));
                $buffer = \substr($buffer, $at + \strlen($delimiter));

                if (null === $name) {
                    continue; // PHP skips a part without a Content-Disposition
                }
                if (null === $filename) {
                    $fields[] = [$name, $value];
                } elseif ($isFile) {
                    ++$uploads;
                    $path = \stream_get_meta_data($file)['uri'];
                    \fclose($file);
                    if ('' === $filename && 0 === $size) {
                        $files[] = [$name, new UploadedFile(StreamFactory::create(''), '', '', 0, \UPLOAD_ERR_NO_FILE)];
                    } elseif ($tooBig) {
                        $files[] = [$name, new UploadedFile(StreamFactory::create(''), $filename, '', 0, \UPLOAD_ERR_INI_SIZE)];
                    } else {
                        $files[] = [$name, new UploadedFile($path, $filename, $type, $size, \UPLOAD_ERR_OK)];
                    }
                }
            }
        } catch (\LengthException) {
            $this->tooLarge($max);

            return;
        } finally {
            if (isset($file) && \is_resource($file)) {
                \fclose($file);
            }
            // As PHP builds $_POST and $_FILES: parse_str decodes the names
            $pairs = [];
            foreach ($fields as [$name, $value]) {
                $pairs[] = \rawurlencode($name) . '=' . \rawurlencode($value);
            }
            \parse_str(\implode('&', $pairs), $this->fields);
            $this->files = self::nest($files);
        }
    }

    /**
     * A part's name, filename (null when not a file), Content-Type, and whether it had a
     * Content-Disposition, from its head.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: bool}
     */
    private static function head(string $head): array
    {
        $name        = $filename = $type = null;
        $disposition = false;
        foreach (\explode("\r\n", $head) as $line) {
            [$header, $value] = \explode(':', $line, 2) + [1 => ''];
            $header           = \strtolower(\trim($header));
            if ('content-disposition' === $header) {
                $disposition = true;
                \preg_match_all('/;\s*(name|filename)\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^;\s]*))/i', $value, $params, \PREG_SET_ORDER);
                foreach ($params as $p) {
                    $v = isset($p[3]) && '' !== $p[3] ? $p[3] : \stripcslashes($p[2]);
                    'name' === \strtolower($p[1]) ? $name = $v : $filename = $v;
                }
            } elseif ('content-type' === $header) {
                $type = \trim($value);
            }
        }
        if (null !== $filename) {
            // Only the name, never a path the client chose
            $filename = \basename(\str_replace('\\', '/', $filename));
        }

        return [$name, $filename, $type, $disposition];
    }

    /**
     * The files nested by their names, as parse_str nests values: `a[b][]` and all.
     *
     * @param list<array{0: string, 1: UploadedFile}> $files
     */
    private static function nest(array $files): array
    {
        if (!$files) {
            return [];
        }
        $pairs = [];
        foreach ($files as $i => [$name]) {
            $pairs[] = \rawurlencode($name) . '=' . $i;
        }
        \parse_str(\implode('&', $pairs), $tree);
        \array_walk_recursive($tree, static function (&$leaf) use ($files) {
            $leaf = $files[(int) $leaf][1];
        });

        return $tree;
    }

    /** @return resource */
    private function temporaryFile()
    {
        $dir  = \ini_get('upload_tmp_dir') ?: \sys_get_temp_dir();
        $path = \tempnam($dir, 'php');
        $this->temporary[] = $path;

        return \fopen($path, 'w');
    }

    private function tooLarge(int $max): void
    {
        Swerve::log()->warning('POST Content-Length exceeds the limit of {max} bytes (post_max_size): the form is empty', ['max' => $max]);
        $this->fields = [];
        $this->files  = [];
        $this->input  = StreamFactory::create('');
    }
}
