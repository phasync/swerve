<?php

namespace Swerve;

use Swerve\Http\ErrorPage;

/**
 * Serves the files of a directory, and passes every other request on to the application's handler.
 *
 * It is what {@see Swerve::accept()} uses for its `$staticPath`.
 *
 * A GET or HEAD request whose path names a file below the directory gets that file: streamed,
 * with its Content-Type (by extension), Content-Length, Last-Modified and ETag, or in its
 * place a compressed copy beside it (`app.js.br`, `.zst`, `.gz`) for a client that accepts that
 * encoding (`Content-Encoding`, `Vary: Accept-Encoding`); `304 Not
 * Modified` for If-None-Match or If-Modified-Since; a single byte range (`Range: bytes=`) as
 * `206 Partial Content`, or 416 when it lies past the end. A directory is served by its
 * index.html, after a redirect to the path with a trailing slash.
 *
 * Not served, but passed on to the application: other methods, a path that leaves the
 * directory (`..`, a symlink pointing outside it), a name starting with a dot (`.env`,
 * `.git/`; `.well-known/` is served), a PHP file (`.php`, `.phtml`, `.phar`, `.inc`: a source is
 * never sent), and a directory without index.html. Nothing is ever listed.
 *
 * ```php
 * // swerve.php: the files under public/, and index.php for the rest
 * phasync::await(Swerve\Swerve::accept(__DIR__ . '/public', __DIR__ . '/public/index.php'));
 * ```
 *
 * @topic request-handlers
 *
 * @example examples/StaticFiles/1-public-directory.php Files, and the application for the rest
 * @example examples/StaticFiles/2-ranges-and-revalidation.php Ranges, revalidation, and no PHP sources
 */
final class StaticFiles
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
        'gz' => 'application/gzip', 'webmanifest' => 'application/manifest+json', 'jsonld' => 'application/ld+json',
        'xhtml' => 'application/xhtml+xml', 'rss' => 'application/rss+xml', 'atom' => 'application/atom+xml',
        'ics' => 'text/calendar; charset=utf-8', 'apng' => 'image/apng', 'bmp' => 'image/bmp', 'tif' => 'image/tiff',
        'tiff' => 'image/tiff', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'flac' => 'audio/flac', 'opus' => 'audio/opus',
        'mov' => 'video/quicktime', 'm4v' => 'video/mp4', 'ogv' => 'video/ogg', 'tar' => 'application/x-tar',
        '7z' => 'application/x-7z-compressed', 'epub' => 'application/epub+zip', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /** The compressed copies looked for beside a file, by content coding, in order of preference. */
    private const ENCODINGS = ['br' => '.br', 'zstd' => '.zst', 'gzip' => '.gz'];

    /** The directory's real path. */
    public readonly string $root;

    /**
     * Serve the files below `$directory`.
     *
     * ```php
     * $files = new StaticFiles(__DIR__ . '/public');
     * ```
     *
     * @param string $directory the document root; resolved to its real path now
     *
     * @throws \InvalidArgumentException when `$directory` is not a directory
     */
    public function __construct(string $directory)
    {
        $root = \realpath($directory);
        if (false === $root || !\is_dir($root)) {
            throw new \InvalidArgumentException("$directory is not a directory");
        }
        $this->root = $root;
    }

    /**
     * The handler that answers $request from the directory, when it names a file there (or a
     * directory with an index.html); null when the application answers it.
     *
     * @return ?\Closure(ClientRequest): void
     *
     * @internal the connection asks before it runs the application
     */
    public function route(ClientRequest $request): ?\Closure
    {
        $method = $request->getMethod();
        $target = $request->getTarget();
        $path   = \rawurldecode((string) \parse_url($target, \PHP_URL_PATH));
        if (('GET' !== $method && 'HEAD' !== $method) || \str_contains($path, "\0") || \preg_match('#/\.(?!well-known/)|\.(?:php\d?|phps|phtml|phar|inc)$#i', $path)) {
            return null;
        }
        \clearstatcache(true, $this->root . $path);
        $file = \realpath($this->root . $path);
        if (false === $file || ($file !== $this->root && !\str_starts_with($file, $this->root . '/'))) {
            return null;
        }
        if (\is_dir($file)) {
            if (!\is_file("$file/index.html")) {
                return null;
            }
            if (!\str_ends_with($path, '/')) {
                $query    = \parse_url($target, \PHP_URL_QUERY);
                $location = \parse_url($target, \PHP_URL_PATH) . '/' . (null !== $query && '' !== $query ? "?$query" : '');

                return static function (ClientRequest $request) use ($location): void {
                    $request->sendResponseHeaders(301, ['Location' => $location]);
                    $request->end();
                };
            }
            $file .= '/index.html';
        }

        return fn (ClientRequest $request) => $this->serve($request, $file);
    }

    private function serve(ClientRequest $request, string $file): void
    {
        $in   = $request->getRequestHeaders();
        $type = self::TYPES[\strtolower(\pathinfo($file, \PATHINFO_EXTENSION))] ?? 'application/octet-stream';
        $stat = @\stat($file);
        // A compressed copy beside the file (style.css.br, style.css.gz), not older than it, for a
        // client that accepts its encoding: served in its place, as nginx's gzip_static does
        $vary     = [];
        $encoding = null;
        if (false !== $stat) {
            foreach (self::ENCODINGS as $coding => $suffix) {
                if (!\is_file($file . $suffix)) {
                    continue;
                }
                $variant = \stat($file . $suffix); // from is_file()'s stat cache
                if ($variant['mtime'] < $stat['mtime'] || !\str_starts_with((string) \realpath($file . $suffix), $this->root . '/')) {
                    continue;
                }
                $vary = ['Vary' => 'Accept-Encoding'];
                if (null === $encoding && self::accepts($in['accept-encoding'] ?? [], $coding)) {
                    $encoding = $coding;
                    $file    .= $suffix;
                    $stat     = $variant;
                }
            }
        }
        $fp = false !== $stat ? @\fopen($file, 'r') : false;
        if (false === $fp) {
            ErrorPage::send($request, 403);

            return;
        }
        try {
            $size     = $stat['size'];
            $modified = \gmdate('D, d M Y H:i:s', $stat['mtime']) . ' GMT';
            $etag     = '"' . \dechex($stat['mtime']) . '-' . \dechex($size) . (null === $encoding ? '' : "-$encoding") . '"';
            $headers  = [
                'Content-Type'  => $type,
                'Last-Modified' => $modified,
                'ETag'          => $etag,
                'Accept-Ranges' => 'bytes',
            ] + $vary + (null === $encoding ? [] : ['Content-Encoding' => $encoding]);
            $ifNoneMatch = \implode(', ', $in['if-none-match'] ?? []);
            if ('' !== $ifNoneMatch
                ? \in_array($etag, \array_map(static fn ($t) => \preg_replace('#^W/#', '', \trim($t)), \explode(',', $ifNoneMatch)), true) || '*' === \trim($ifNoneMatch)
                : ('' !== ($since = \implode(', ', $in['if-modified-since'] ?? [])) && false !== ($t = \strtotime($since)) && $stat['mtime'] <= $t)) {
                $request->sendResponseHeaders(304, $headers);
                $request->end();

                return;
            }

            // One range; several are answered with the whole file, as RFC 9110 allows
            $range   = \implode(', ', $in['range'] ?? []);
            $ifRange = \implode(', ', $in['if-range'] ?? []);
            if (\preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) && ('' !== $m[1] || '' !== $m[2]) && ('' === $ifRange || $ifRange === $etag || $ifRange === $modified)) {
                if ('' === $m[1]) {
                    $start = \max(0, $size - (int) $m[2]);
                    $end   = $size - 1;
                } else {
                    $start = (int) $m[1];
                    $end   = '' === $m[2] ? $size - 1 : \min((int) $m[2], $size - 1);
                }
                if ($start >= $size || $start > $end) {
                    $request->sendResponseHeaders(416, ['Content-Range' => "bytes */$size"] + $headers);
                    $request->end();

                    return;
                }
                $length = $end - $start + 1;
                $request->sendResponseHeaders(206, ['Content-Range' => "bytes $start-$end/$size", 'Content-Length' => (string) $length] + $headers);
                $request->sendFile($fp, $start, $length);

                return;
            }

            $request->sendResponseHeaders(200, ['Content-Length' => (string) $size] + $headers);
            $request->sendFile($fp);
        } finally {
            \fclose($fp);
        }
    }

    /**
     * Whether an Accept-Encoding header accepts $coding: named, or by `*`, with a q above 0.
     *
     * @param list<string> $header
     */
    private static function accepts(array $header, string $coding): bool
    {
        $any = false;
        foreach (\explode(',', \strtolower(\implode(',', $header))) as $item) {
            $parts = \explode(';', $item);
            $name  = \trim($parts[0]);
            $q     = 1.0;
            foreach (\array_slice($parts, 1) as $param) {
                if (\preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/', $param, $m)) {
                    $q = (float) $m[1];
                }
            }
            if ($name === $coding) {
                return $q > 0;
            }
            if ('*' === $name) {
                $any = $q > 0;
            }
        }

        return $any;
    }
}
