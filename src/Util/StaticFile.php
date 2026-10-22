<?php

namespace Swerve\Util;

use Swerve\ClientRequest;

/**
 * Sends a file from the public root, as {@see Router} chose it: with its Content-Type (by
 * extension), Content-Length, Last-Modified and ETag; revalidation and single byte ranges.
 *
 * @internal
 */
final class StaticFile
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

    /**
     * Send a file: GET and HEAD only (405 otherwise); 304 for a matching If-None-Match or
     * If-Modified-Since; one byte range as 206 (416 past the end); 403 when it can't be read.
     */
    public static function send(ClientRequest $request, string $file): void
    {
        $method = $request->getMethod();
        if ('GET' !== $method && 'HEAD' !== $method) {
            $request->sendResponseHeaders(405, ['Allow' => 'GET, HEAD', 'Content-Length' => '0']);
            $request->end();

            return;
        }
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
