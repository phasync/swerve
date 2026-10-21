<?php

namespace Swerve\Classic;

use phasync\Net\Duplex;
use Swerve\ClientRequest;

use function phasync\Net\dial;

/**
 * Forwards an HTTP exchange to a `php -S` process of a {@see ClassicPool}: method, URI, headers
 * and body, streamed both ways rather than buffered whole.
 *
 * The client's identity is passed as headers only this class sets (`X-Swerve-Remote-Addr`,
 * `-Https`, `-Server-Port`, `-Host`); any client-sent copies are stripped first, and the backend's
 * `auto_prepend_file` (see Classic/prepend.php) turns them into `REMOTE_ADDR`, `HTTPS`,
 * `SERVER_PORT` and the host, then removes them from `$_SERVER` before the front controller runs.
 *
 * Request body: forwarded as it arrives from the client. With a `Content-Length` (the common
 * case) that is sent on to the backend unchanged and exactly that many bytes are relayed; without
 * one (`Transfer-Encoding: chunked`, whose original chunk boundaries swerve has already decoded
 * away, so they cannot be passed through as sent) the bytes read are re-chunked on the way out —
 * PHP's built-in server accepts a chunked request body (verified directly: a hand-built chunked
 * POST decodes correctly into `php://input`), so this needs no buffering either.
 *
 * Response body: the status line and headers are read, then the body is relayed as it arrives —
 * by `Content-Length` when the backend sent one, else chunk by chunk (never passed through
 * as framed: {@see ClientRequest}'s own docs say a `transfer-encoding` of `chunked` is "the same
 * as none", so the raw chunk bytes would be framed a second time). The pool slot is released the
 * moment the backend's own side of the exchange is finished reading (its `Content-Length` is
 * reached, or its last chunk, or it closes) — *not* when the last byte has reached the client —
 * so a `php -S` process is occupied only for as long as it is actually computing, never for
 * however long a slow client takes to read; otherwise a few slow clients would starve the pool
 * while idle processes sit released-but-unusable behind them.
 *
 * One backend connection per request (`Connection: close`, and `php -S` sends one back regardless
 * of what is asked): PHP's built-in server never keeps a connection for a second request, so
 * nothing would be gained by trying to. But a connection `php -S` has *accepted* and not yet
 * read from sits waiting just fine, so {@see ClassicPool::start()} keeps its readiness probe as
 * the process's first ready connection, and every exchange here dials the *next* one right after
 * reading the current response (a background coroutine, not on this request's path): the next
 * request to use this process then writes at once instead of paying a connect first.
 *
 * Every wait here, on the backend or the client, is bounded by {@see ClassicProxy::IO_TIMEOUT}:
 * found the hard way — a client that disconnects mid-response (a benchmark ending its run closes
 * every connection, which is the ordinary case for this) left the write to it waiting forever
 * rather than failing, which pinned the process the request held and, with that repeated for
 * every busy process at once, froze the whole pool permanently (one worker owns it; nothing else
 * could ever acquire() again either). An explicit timeout here resolves it even where the
 * client's own default did not.
 *
 * @internal
 */
final class ClassicProxy
{
    /** Request headers never forwarded: hop-by-hop, recomputed, or the identity headers we set ourselves. */
    private const STRIP_REQUEST = [
        'connection', 'keep-alive', 'transfer-encoding', 'content-length', 'upgrade',
        'proxy-authenticate', 'proxy-authorization', 'te', 'trailers', 'host',
        'x-swerve-remote-addr', 'x-swerve-https', 'x-swerve-server-port', 'x-swerve-host',
    ];

    /** Response headers never relayed as the backend sent them: swerve frames the body itself. */
    private const STRIP_RESPONSE = ['connection', 'keep-alive', 'transfer-encoding', 'content-length'];

    public function __construct(private readonly ClassicPool $pool)
    {
    }

    /** A handler that proxies every request through the pool. */
    public function handler(): \Closure
    {
        return function (ClientRequest $request): void {
            $process  = $this->pool->acquire();
            $released = false;
            $release  = function () use (&$released, $process): void {
                if (!$released) {
                    $released = true;
                    $this->pool->release($process);
                }
            };
            try {
                $this->exchange($request, $process, $release);
            } catch (\Throwable $e) {
                // $process->stop() already happened wherever this came from the *backend*
                // breaking (backendRead()/backendWrite()/dial(), or a malformed response);
                // a client that went away mid-response throws too, but the backend is fine —
                // stopping a perfectly good process on every client disconnect froze the whole
                // pool under a benchmark's end-of-run mass disconnect (every busy process
                // stopped at once, each wait of ProcessInterface::stop() serialized on this
                // one worker's single coroutine).
                $release();
                throw $e; // unsent head: swerve answers 500; sent: swerve aborts the connection
            }
            $release(); // a no-op unless the exchange never reached the point of releasing itself
        };
    }

    /** Sends the request to the backend and streams its response to $request. */
    private function exchange(ClientRequest $request, ClassicProcess $process, \Closure $release): void
    {
        $headers = $request->getRequestHeaders();
        $host    = $headers['host'][0] ?? $request->local();
        $length  = isset($headers['content-length'][0]) ? (int) $headers['content-length'][0] : null;
        $chunked = null === $length && self::isChunked($headers['transfer-encoding'] ?? []);
        foreach (self::STRIP_REQUEST as $name) {
            unset($headers[$name]);
        }

        $remote = self::addressOf($request->peer());
        $port   = self::portOf($request->local());

        $lines   = [$request->getMethod() . ' ' . $request->getTarget() . ' HTTP/1.1'];
        $lines[] = "Host: $host";
        foreach ($headers as $name => $values) {
            $label = \implode('-', \array_map('ucfirst', \explode('-', $name)));
            foreach ($values as $value) {
                $lines[] = "$label: $value";
            }
        }
        $lines[] = "X-Swerve-Remote-Addr: $remote";
        if ('https' === $request->getScheme()) {
            $lines[] = 'X-Swerve-Https: on';
        }
        $lines[] = "X-Swerve-Server-Port: $port";
        $lines[] = "X-Swerve-Host: $host";
        $lines[] = $chunked ? 'Transfer-Encoding: chunked' : ('Content-Length: ' . ($length ?? 0));
        $lines[] = 'Connection: close';

        if (null !== $process->conn) {
            $conn = $process->conn;
        } else {
            try {
                $conn = dial("tcp://127.0.0.1:{$process->port}", 10.0);
            } catch (\Throwable $e) {
                $process->stop(); // never started talking to it: nothing to release it from either

                throw $e;
            }
        }
        $process->conn = null; // claimed: a stale handle here would be reused by someone else
        try {
            $this->backendWrite($conn, $process, \implode("\r\n", $lines) . "\r\n\r\n");
            if (null !== $length) {
                for ($sent = 0; $sent < $length;) {
                    $chunk = $request->read();
                    if ('' === $chunk) {
                        break; // the client ended early; the backend sees a short body
                    }
                    $this->backendWrite($conn, $process, $chunk);
                    $sent += \strlen($chunk);
                }
            } elseif ($chunked) {
                while ('' !== ($chunk = $request->read())) {
                    $this->backendWrite($conn, $process, \dechex(\strlen($chunk)) . "\r\n$chunk\r\n");
                }
                $this->backendWrite($conn, $process, "0\r\n\r\n");
            }

            $this->relayResponse($request, $conn, $process, $release);
        } finally {
            $conn->close();
            $this->preconnect($process);
        }
    }

    /** Bounds every wait on the backend or the client: nothing here should ever hang forever. */
    private const IO_TIMEOUT = 10.0;

    /** $conn->write(), but a broken backend connection stops $process: don't hand it back as healthy. */
    private function backendWrite(Duplex $conn, ClassicProcess $process, string $data): void
    {
        try {
            $conn->write($data, self::IO_TIMEOUT);
        } catch (\Throwable $e) {
            $process->stop();

            throw $e;
        }
    }

    /** $conn->read(), but a broken backend connection stops $process: don't hand it back as healthy. */
    private function backendRead(Duplex $conn, ClassicProcess $process): string
    {
        try {
            return $conn->read(65536, self::IO_TIMEOUT);
        } catch (\Throwable $e) {
            $process->stop();

            throw $e;
        }
    }

    /** @param list<string> $values */
    private static function isChunked(array $values): bool
    {
        foreach ($values as $value) {
            if (false !== \stripos($value, 'chunked')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dials the connection this process's next request will find ready. Not backgrounded: a
     * connection like HttpConnection's runs each request in a Scope of its own that joins every
     * coroutine started inside it, so a `phasync::go()` here would make the *client's* connection
     * wait for this dial before it could be read for its next request — worse than not
     * pre-connecting at all. The dial itself is a loopback connect, cheap enough on this request's
     * own path. Best-effort: a process stopped or reaped meanwhile just leaves
     * {@see ClassicProcess::$conn} null, and the next exchange() dials for itself.
     */
    private function preconnect(ClassicProcess $process): void
    {
        if (!ClassicIni::preconnect() || !$process->isRunning()) {
            return;
        }
        try {
            $conn = dial("tcp://127.0.0.1:{$process->port}", 10.0);
        } catch (\Throwable) {
            return;
        }
        if ($process->isRunning()) {
            $process->conn = $conn;
        } else {
            $conn->close();
        }
    }

    /** Reads the response head, sends it on, then streams the body; releases $process as soon as the backend's side of it is read. */
    private function relayResponse(ClientRequest $request, Duplex $conn, ClassicProcess $process, \Closure $release): void
    {
        $buf = '';
        while (!\str_contains($buf, "\r\n\r\n")) {
            $chunk = $this->backendRead($conn, $process);
            if ('' === $chunk) {
                $process->stop();
                $release();

                throw new \RuntimeException('classic: the backend closed the connection before sending a response head');
            }
            $buf .= $chunk;
        }
        [$head, $rest] = \explode("\r\n\r\n", $buf, 2);
        $headLines = \explode("\r\n", $head);
        if (!\preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $headLines[0], $m)) {
            $process->stop();
            $release();

            throw new \RuntimeException("classic: not an HTTP response: {$headLines[0]}");
        }
        $status = (int) $m[1];

        $contentLength = null;
        $chunkedIn     = false;
        $outHeaders    = [];
        foreach (\array_slice($headLines, 1) as $line) {
            $pos = \strpos($line, ':');
            if (false === $pos) {
                continue;
            }
            $name  = \trim(\substr($line, 0, $pos));
            $value = \trim(\substr($line, $pos + 1));
            $lower = \strtolower($name);
            if ('content-length' === $lower) {
                $contentLength = (int) $value;
            }
            if ('transfer-encoding' === $lower && false !== \stripos($value, 'chunked')) {
                $chunkedIn = true;
            }
            if (\in_array($lower, self::STRIP_RESPONSE, true)) {
                continue;
            }
            $outHeaders[$name][] = $value;
        }

        if (null !== $contentLength) {
            $request->sendResponseHeaders($status, $outHeaders + ['Content-Length' => (string) $contentLength]);
            $sent = 0;
            while ($sent < $contentLength) {
                if ('' === $rest) {
                    $rest = $this->backendRead($conn, $process);
                    if ('' === $rest) {
                        break; // the backend closed early; forward what arrived
                    }
                }
                $take = \substr($rest, 0, $contentLength - $sent);
                $rest = \substr($rest, \strlen($take));
                $sent += \strlen($take);
                if ($sent >= $contentLength) {
                    $release(); // the backend's side is done; free it before the last write, not after
                }
                $request->write($take, self::IO_TIMEOUT);
                $request->flush();
            }
            $release(); // the backend closed early: also done with it, just short a response
            $request->end();

            return;
        }

        $request->sendResponseHeaders($status, $outHeaders);
        if ($chunkedIn) {
            $this->relayChunked($request, $conn, $process, $rest, $release);
        } else {
            // Close-delimited: everything until the backend ends the connection is the body.
            // Each piece is written as soon as it is read (no lookahead: holding a piece back
            // to check whether another follows would delay it by a whole read cycle, defeating
            // streaming); release() only follows once a read finds the backend actually done,
            // since nothing short of that says so for this framing.
            if ('' !== $rest) {
                $request->write($rest, self::IO_TIMEOUT);
                $request->flush();
            }
            while ('' !== ($next = $this->backendRead($conn, $process))) {
                $request->write($next, self::IO_TIMEOUT);
                $request->flush();
            }
            $release();
        }
        $request->end();
    }

    private function relayChunked(ClientRequest $request, Duplex $conn, ClassicProcess $process, string $buf, \Closure $release): void
    {
        while (true) {
            while (false === ($eol = \strpos($buf, "\r\n"))) {
                $buf .= $this->mustRead($conn, $process, $release);
            }
            $sizeLine = \substr($buf, 0, $eol);
            $buf      = \substr($buf, $eol + 2);
            $size     = \hexdec(\trim(\explode(';', $sizeLine, 2)[0]));
            if (0 === $size) {
                // Trailer lines, if any, then a final blank line; trailers are not forwarded.
                for ($tries = 0; !\str_starts_with($buf, "\r\n") && $tries < 100; ++$tries) {
                    $eol = \strpos($buf, "\r\n");
                    if (false === $eol) {
                        $buf .= $this->mustRead($conn, $process, $release);
                        continue;
                    }
                    $buf = \substr($buf, $eol + 2);
                }
                $release();

                return;
            }
            while (\strlen($buf) < $size + 2) {
                $buf .= $this->mustRead($conn, $process, $release);
            }
            $request->write(\substr($buf, 0, $size), self::IO_TIMEOUT);
            $request->flush();
            $buf = \substr($buf, $size + 2); // the chunk's own trailing CRLF
        }
    }

    private function mustRead(Duplex $conn, ClassicProcess $process, \Closure $release): string
    {
        $chunk = $this->backendRead($conn, $process);
        if ('' === $chunk) {
            $process->stop();
            $release();

            throw new \RuntimeException('classic: the backend closed the connection mid-chunk');
        }

        return $chunk;
    }

    /** 'host:port' or '[ipv6]:port' to just the address. */
    private static function addressOf(string $hostPort): string
    {
        return \trim(\substr($hostPort, 0, \strrpos($hostPort, ':')), '[]');
    }

    /** 'host:port' or '[ipv6]:port' to just the port. */
    private static function portOf(string $hostPort): string
    {
        return \substr($hostPort, \strrpos($hostPort, ':') + 1);
    }
}
