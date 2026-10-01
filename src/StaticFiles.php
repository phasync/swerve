<?php

namespace Swerve;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use phasync\Psr\Response;
use phasync\Psr\StreamFactory;

/**
 * Serve the files of a directory, and pass every other request on: `--public=<dir>`, or as
 * middleware of your own, `new StaticFiles(__DIR__ . '/public')`.
 *
 * A GET or HEAD request whose path names a file below the directory gets that file: streamed,
 * with its Content-Type (by extension), Content-Length, Last-Modified and ETag; `304 Not
 * Modified` for If-None-Match or If-Modified-Since; a single byte range (`Range: bytes=`) as
 * `206 Partial Content`, or 416 when it lies past the end. A directory is served by its
 * index.html, after a redirect to the path with a trailing slash.
 *
 * Not served, but passed on to the application: other methods, a path that leaves the
 * directory (`..`, a symlink pointing outside it), a name starting with a dot (`.env`,
 * `.git/`; `.well-known/` is served), a PHP file (`.php`, `.phtml`, `.phar`, `.inc`: a source is
 * never sent), and a directory without index.html. Nothing is ever listed.
 */
final class StaticFiles implements MiddlewareInterface
{
    private const TYPES = [
        'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8', 'json' => 'application/json',
        'map' => 'application/json', 'txt' => 'text/plain; charset=utf-8', 'md' => 'text/markdown; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8', 'xml' => 'application/xml', 'svg' => 'image/svg+xml', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
        'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
        'pdf' => 'application/pdf', 'wasm' => 'application/wasm', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
        'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav', 'zip' => 'application/zip',
        'gz' => 'application/gzip', 'webmanifest' => 'application/manifest+json',
    ];

    private readonly string $root;

    /**
     * @throws \InvalidArgumentException when $directory is not a directory
     */
    public function __construct(string $directory)
    {
        $root = \realpath($directory);
        if (false === $root || !\is_dir($root)) {
            throw new \InvalidArgumentException("$directory is not a directory");
        }
        $this->root = $root;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();
        $path   = \rawurldecode($request->getUri()->getPath());
        if (('GET' !== $method && 'HEAD' !== $method) || \str_contains($path, "\0") || \preg_match('#/\.(?!well-known/)|\.(?:php\d?|phps|phtml|phar|inc)$#i', $path)) {
            return $handler->handle($request);
        }
        \clearstatcache(true, $this->root . $path);
        $file = \realpath($this->root . $path);
        if (false === $file || ($file !== $this->root && !\str_starts_with($file, $this->root . '/'))) {
            return $handler->handle($request);
        }
        if (\is_dir($file)) {
            if (!\is_file("$file/index.html")) {
                return $handler->handle($request);
            }
            if (!\str_ends_with($path, '/')) {
                $query = $request->getUri()->getQuery();

                return new Response(301, ['Location' => $request->getUri()->getPath() . '/' . ('' !== $query ? "?$query" : '')], '');
            }
            $file .= '/index.html';
        }

        return $this->serve($request, $file);
    }

    private function serve(ServerRequestInterface $request, string $file): ResponseInterface
    {
        $stat = @\stat($file);
        $fp   = false !== $stat ? @\fopen($file, 'r') : false;
        if (false === $fp) {
            return new Response(403, [], '');
        }
        $size     = $stat['size'];
        $modified = \gmdate('D, d M Y H:i:s', $stat['mtime']) . ' GMT';
        $etag     = '"' . \dechex($stat['mtime']) . '-' . \dechex($size) . '"';
        $headers  = [
            'Content-Type'  => self::TYPES[\strtolower(\pathinfo($file, \PATHINFO_EXTENSION))] ?? 'application/octet-stream',
            'Last-Modified' => $modified,
            'ETag'          => $etag,
            'Accept-Ranges' => 'bytes',
        ];

        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ('' !== $ifNoneMatch
            ? \in_array($etag, \array_map(static fn ($t) => \preg_replace('#^W/#', '', \trim($t)), \explode(',', $ifNoneMatch)), true) || '*' === \trim($ifNoneMatch)
            : ('' !== ($since = $request->getHeaderLine('If-Modified-Since')) && false !== ($t = \strtotime($since)) && $stat['mtime'] <= $t)) {
            \fclose($fp);

            return new Response(304, $headers, '');
        }

        // One range; several are answered with the whole file, as RFC 9110 allows
        $range   = $request->getHeaderLine('Range');
        $ifRange = $request->getHeaderLine('If-Range');
        if (\preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) && ('' !== $m[1] || '' !== $m[2]) && ('' === $ifRange || $ifRange === $etag || $ifRange === $modified)) {
            if ('' === $m[1]) {
                $start = \max(0, $size - (int) $m[2]);
                $end   = $size - 1;
            } else {
                $start = (int) $m[1];
                $end   = '' === $m[2] ? $size - 1 : \min((int) $m[2], $size - 1);
            }
            if ($start >= $size || $start > $end) {
                \fclose($fp);

                return new Response(416, ['Content-Range' => "bytes */$size"] + $headers, '');
            }
            \fseek($fp, $start);
            $length = $end - $start + 1;

            return new Response(206, ['Content-Range' => "bytes $start-$end/$size", 'Content-Length' => (string) $length] + $headers, self::part($fp, $length));
        }

        return new Response(200, ['Content-Length' => (string) $size] + $headers, StreamFactory::create($fp));
    }

    /**
     * The $length bytes of $fp from where it is: a stream that can't be sought, since a server
     * rewinds a seekable body to its start before sending it.
     *
     * @param resource $fp
     */
    private static function part($fp, int $length): StreamInterface
    {
        return new class($fp, $length) implements StreamInterface {
            private int $left;

            /** @param resource $fp */
            public function __construct(private $fp, private readonly int $length)
            {
                $this->left = $length;
            }

            public function read(int $length): string
            {
                if ($this->left <= 0) {
                    return '';
                }
                $data = (string) \fread($this->fp, \min($length, $this->left));
                $this->left -= \strlen($data);
                if ('' === $data) {
                    $this->left = 0; // the file shrank meanwhile
                }

                return $data;
            }

            public function eof(): bool
            {
                return $this->left <= 0;
            }

            public function getSize(): int
            {
                return $this->length;
            }

            public function tell(): int
            {
                return $this->length - $this->left;
            }

            public function getContents(): string
            {
                $out = '';
                while ('' !== ($data = $this->read(65536))) {
                    $out .= $data;
                }

                return $out;
            }

            public function close(): void
            {
                if (\is_resource($this->fp)) {
                    \fclose($this->fp);
                }
                $this->left = 0;
            }

            public function detach()
            {
                $fp       = $this->fp;
                $this->fp = null;

                return $fp;
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function seek(int $offset, int $whence = \SEEK_SET): void
            {
                throw new \RuntimeException('Not seekable');
            }

            public function rewind(): void
            {
                throw new \RuntimeException('Not seekable');
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                throw new \RuntimeException('Not writable');
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function getMetadata(?string $key = null)
            {
                return null === $key ? [] : null;
            }

            public function __toString(): string
            {
                return $this->getContents();
            }
        };
    }
}
