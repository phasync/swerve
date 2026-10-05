<?php

namespace Swerve;

/**
 * Serves the files of a directory, and passes every other request on to the application's handler.
 *
 * It is what `--public=<dir>` installs; wrap your own handler for the same behaviour.
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
 *
 * ```php
 * $files = new Swerve\StaticFiles(__DIR__ . '/public');
 * return new Swerve\RequestHandler($files->wrap(function (Swerve\ClientRequest $request) {
 *     // what is not a file
 * }));
 * ```
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
        'gz' => 'application/gzip', 'webmanifest' => 'application/manifest+json',
    ];

    private readonly string $root;

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
     * A handler that answers the requests naming a file from the directory, and calls `$app` for
     * every other.
     *
     * @param \Closure(ClientRequest): void $app the application's handler
     *
     * @return \Closure(ClientRequest): void
     */
    public function wrap(\Closure $app): \Closure
    {
        return function (ClientRequest $request) use ($app): void {
            $method = $request->getMethod();
            $target = $request->getTarget();
            $path   = \rawurldecode((string) \parse_url($target, \PHP_URL_PATH));
            if (('GET' !== $method && 'HEAD' !== $method) || \str_contains($path, "\0") || \preg_match('#/\.(?!well-known/)|\.(?:php\d?|phps|phtml|phar|inc)$#i', $path)) {
                $app($request);

                return;
            }
            \clearstatcache(true, $this->root . $path);
            $file = \realpath($this->root . $path);
            if (false === $file || ($file !== $this->root && !\str_starts_with($file, $this->root . '/'))) {
                $app($request);

                return;
            }
            if (\is_dir($file)) {
                if (!\is_file("$file/index.html")) {
                    $app($request);

                    return;
                }
                if (!\str_ends_with($path, '/')) {
                    $query = \parse_url($target, \PHP_URL_QUERY);
                    $request->sendResponseHeaders(301, ['Location' => \parse_url($target, \PHP_URL_PATH) . '/' . (null !== $query && '' !== $query ? "?$query" : '')]);
                    $request->end();

                    return;
                }
                $file .= '/index.html';
            }
            $this->serve($request, $file);
        };
    }

    private function serve(ClientRequest $request, string $file): void
    {
        $stat = @\stat($file);
        $fp   = false !== $stat ? @\fopen($file, 'r') : false;
        if (false === $fp) {
            $request->sendResponseHeaders(403);
            $request->end();

            return;
        }
        try {
            $size     = $stat['size'];
            $modified = \gmdate('D, d M Y H:i:s', $stat['mtime']) . ' GMT';
            $etag     = '"' . \dechex($stat['mtime']) . '-' . \dechex($size) . '"';
            $headers  = [
                'Content-Type'  => self::TYPES[\strtolower(\pathinfo($file, \PATHINFO_EXTENSION))] ?? 'application/octet-stream',
                'Last-Modified' => $modified,
                'ETag'          => $etag,
                'Accept-Ranges' => 'bytes',
            ];
            $in          = $request->getRequestHeaders();
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
}
