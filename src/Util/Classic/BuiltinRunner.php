<?php

namespace Swerve\Util\Classic;

use phasync\IOException;
use phasync\Net\Duplex;
use phasync\Util\Process;
use Swerve\ClientRequest;

use function phasync\Net\dial;

/**
 * Runs Classic scripts on a pool of `php -S` processes, one per worker, for applications without
 * ext-phasync: the sibling of {@see ServerRunner}, which runs them on the extension's request
 * server instead.
 *
 * A process serves its requests one at a time: `php -S` never keeps a connection for a second
 * request, so each exchange dials a fresh one (`Connection: close`, and the backend sends one
 * back regardless of what is asked). The pool grows lazily, a process started only when
 * {@see BuiltinRunner::acquire()} finds no idle one, up to `$max`; one idle for {@see
 * BuiltinRunner::IDLE} seconds is stopped by a sweeper coroutine started once, in the worker's
 * own frame (not a request's, which a `phasync::go()` here would make wait for it). A process
 * that died while idle is dropped and another is started or waited for in its place; a request
 * that finds `$max` busy waits for a release via {@see \phasync::awaitFlag()}.
 *
 * The router script every process runs is not the Classic application's own router: Swerve's own
 * `Swerve\Util\Router` already chose the script before `forward()` was called, so the one at
 * {@see builtin-router.php} simply runs it, with `$_SERVER` made to match PHP-FPM as closely as
 * `php -S` allows. The script, its SCRIPT_NAME, PATH_INFO and the client's identity travel as
 * request headers only this class sets (`X-Swerve-*`); any client-sent copies of the same names
 * are stripped first (see {@see self::STRIP_REQUEST}).
 *
 * Request and response bodies are streamed, not buffered whole: by `Content-Length` when there
 * is one, else re-chunked on the way in (PHP's built-in server accepts a chunked request body)
 * and relayed by `Content-Length`, chunk by chunk, or until the backend closes the connection, on
 * the way out. The opcache and `output_buffering` flags in {@see self::INI} were chosen by
 * measuring Classic mode against FPM on WordPress; without `output_buffering=0`, `php -S`'s own
 * buffering holds a script's `flush()`ed output back until it ends.
 *
 * The pool slot is released the moment the backend's own side of the exchange is finished
 * reading, not when the last byte has reached the client: otherwise a few slow clients would
 * starve the pool while idle processes sit released-but-unusable behind them. The process's next
 * connection is dialed right away too (not backgrounded: a request's frame waits for every
 * coroutine started inside it, so this stays on the exchange's own path), hiding that connect
 * from the next request that finds this process idle.
 *
 * A backend failure (the process died, or sent something not a valid response head) answers 502
 * when the response head was not sent yet, and closes the client's connection otherwise; it is
 * not reused. The client's own failures surface the same way (see {@see ServerRunner}'s own note
 * on this).
 *
 * @internal
 */
final class BuiltinRunner implements Runner
{
    /** Seconds a process may sit idle (no request) before it is stopped. */
    public const IDLE = 10.0;

    /** Seconds to wait for a freshly started process to accept a connection. */
    private const START_TIMEOUT = 5.0;

    /** Bounds every wait on the backend or the client: nothing here should ever hang forever. */
    private const IO_TIMEOUT = 10.0;

    /**
     * The `-d` flags given to every process, besides the router: opcache on (incl. for the
     * cli-server SAPI, which needs both switches), timestamps not checked, and
     * `output_buffering=0` (see the class docblock).
     */
    private const INI = [
        '-d', 'display_errors=1',
        '-d', 'zend_extension=opcache',
        '-d', 'opcache.enable=1',
        '-d', 'opcache.enable_cli=1',
        '-d', 'opcache.validate_timestamps=0',
        '-d', 'opcache.memory_consumption=256',
        '-d', 'opcache.max_accelerated_files=50000',
        '-d', 'opcache.interned_strings_buffer=16',
        '-d', 'output_buffering=0',
    ];

    /** Request headers never forwarded: hop-by-hop, recomputed, or the private headers we set ourselves. */
    private const STRIP_REQUEST = [
        'connection', 'keep-alive', 'transfer-encoding', 'content-length', 'upgrade',
        'proxy-authenticate', 'proxy-authorization', 'te', 'trailers', 'host',
        'x-swerve-script', 'x-swerve-script-name', 'x-swerve-path-info', 'x-swerve-document-root',
        'x-swerve-remote-addr', 'x-swerve-https', 'x-swerve-server-port', 'x-swerve-host',
    ];

    /** Response headers never relayed as the backend sent them: swerve frames the body itself. */
    private const STRIP_RESPONSE = ['connection', 'keep-alive', 'transfer-encoding', 'content-length'];

    /** @var list<BuiltinProcess> idle processes, the most recently used last */
    private array $idle = [];

    /** Processes started and not yet reaped (idle or busy). */
    private int $open = 0;

    /** Whether a coroutine closes idle processes. */
    private bool $sweeping = false;

    /** The worker's own frame and scope, where the sweeper runs: not a request's, which would wait for it. */
    private readonly \phasync\Frame $frame;
    private readonly \Scope $scope;

    /**
     * Made in the worker's own frame, outside any request.
     *
     * @param int   $max         this worker's share of the processes running Classic scripts
     * @param float $idleSeconds seconds a process may sit idle before it is stopped; overridable for tests
     */
    public function __construct(private readonly string $publicRoot, private readonly int $max, private readonly float $idleSeconds = self::IDLE)
    {
        $this->frame = \phasync::frame();
        $this->scope = \Scope::getCurrent();
    }

    public function forward(ClientRequest $request, string $script, string $scriptName, ?string $pathInfo): void
    {
        $process  = $this->acquire();
        $released = false;
        $release  = function () use (&$released, $process): void {
            if (!$released) {
                $released = true;
                $this->release($process);
            }
        };
        try {
            $this->exchange($request, $process, $script, $scriptName, $pathInfo, $release);
        } catch (\Throwable $e) {
            $release();
            if (!$e instanceof IOException) {
                throw $e;
            }
            // The backend failed: the process is gone (already stopped wherever this came
            // from). The client's own failures are IOExceptions too, and end its exchange the
            // same way (see ServerRunner::exchange()).
            if (!$request->headersSent()) {
                $request->sendResponseHeaders(502, ['Content-Type' => 'text/plain', 'Content-Length' => '12']);
                $request->write("Bad Gateway\n");
                $request->end();
            } else {
                $request->close();
            }

            return;
        }
        $release();
    }

    /** An idle process (if it is still running), a freshly started one, or waits for a release. */
    private function acquire(): BuiltinProcess
    {
        while (true) {
            if ([] !== $this->idle) {
                $process = \array_pop($this->idle);
                if ($process->isRunning()) {
                    return $process;
                }
                --$this->open; // died while idle; try the next one, or start one

                continue;
            }
            if ($this->open < $this->max) {
                ++$this->open;
                try {
                    return $this->start();
                } catch (\Throwable $e) {
                    --$this->open;
                    throw $e;
                }
            }
            \phasync::awaitFlag($this);
        }
    }

    /** Gives a process back: idle with its reap timer armed, unless it died. */
    private function release(BuiltinProcess $process): void
    {
        if (!$process->isRunning()) {
            --$this->open;
            \phasync::raiseFlag($this);

            return;
        }
        $process->idleSince = \microtime(true);
        $this->idle[]        = $process;
        \phasync::raiseFlag($this);
        if (!$this->sweeping) {
            $this->sweeping = true;
            \phasync::getLoop()->create($this->sweep(...), [], $this->frame, $this->scope);
        }
    }

    /** Stops the processes idle longer than {@see self::$idleSeconds}, until none is idle. */
    private function sweep(): void
    {
        try {
            while ([] !== $this->idle) {
                $now = \microtime(true);
                // The least recently used first
                while ([] !== $this->idle && $now - $this->idle[0]->idleSince >= $this->idleSeconds) {
                    \array_shift($this->idle)->stop();
                    --$this->open;
                }
                if ([] !== $this->idle) {
                    \phasync::sleep($this->idleSeconds - ($now - $this->idle[0]->idleSince));
                }
            }
        } finally {
            $this->sweeping = false;
        }
    }

    private function start(): BuiltinProcess
    {
        $port    = self::freePort();
        $router  = __DIR__ . '/builtin-router.php';
        $args    = [...self::INI, '-S', "127.0.0.1:$port", '-t', $this->publicRoot, $router];
        $process = Process::run(\PHP_BINARY, $args, $this->publicRoot);
        $deadline = \microtime(true) + self::START_TIMEOUT;
        while (true) {
            try {
                // Kept, not closed: the readiness probe doubles as the connection ready for the
                // process's first request (see BuiltinProcess::$conn).
                $conn = dial("tcp://127.0.0.1:$port", 0.05);
                break;
            } catch (\Throwable $e) {
                if (!$process->isRunning()) {
                    throw new \RuntimeException("Classic: php -S on 127.0.0.1:$port exited before it accepted a connection (exit {$process->getExitCode()})");
                }
                if (\microtime(true) >= $deadline) {
                    $process->stop();

                    throw new \RuntimeException("Classic: php -S on 127.0.0.1:$port did not accept a connection within " . self::START_TIMEOUT . 's');
                }
                \phasync::sleep(0.01);
            }
        }

        return new BuiltinProcess($process, $port, $conn);
    }

    /** Sends the request to the backend and streams its response to $request. */
    private function exchange(ClientRequest $request, BuiltinProcess $process, string $script, string $scriptName, ?string $pathInfo, \Closure $release): void
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
        $lines[] = "X-Swerve-Script: $script";
        $lines[] = "X-Swerve-Script-Name: $scriptName";
        if (null !== $pathInfo) {
            $lines[] = "X-Swerve-Path-Info: $pathInfo";
        }
        $lines[] = "X-Swerve-Document-Root: {$this->publicRoot}";
        $lines[] = "X-Swerve-Remote-Addr: $remote";
        if ('https' === $request->getScheme()) {
            $lines[] = 'X-Swerve-Https: on';
        }
        $lines[] = "X-Swerve-Server-Port: $port";
        $lines[] = "X-Swerve-Host: $host";
        $lines[] = $chunked ? 'Transfer-Encoding: chunked' : ('Content-Length: ' . ($length ?? 0));
        $lines[] = 'Connection: close';

        $conn = $this->connectTo($process);
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

    /** The process's preconnected connection, or a fresh dial; stops $process and throws IOException on failure. */
    private function connectTo(BuiltinProcess $process): Duplex
    {
        if (null !== $process->conn) {
            $conn           = $process->conn;
            $process->conn  = null; // claimed: a stale handle here would be reused by someone else

            return $conn;
        }
        try {
            return dial("tcp://127.0.0.1:{$process->port}", self::IO_TIMEOUT);
        } catch (\Throwable $e) {
            $process->stop(); // never started talking to it: nothing to release it from either

            throw $e instanceof IOException ? $e : new IOException("Classic: can't connect to php -S on 127.0.0.1:{$process->port}: {$e->getMessage()}");
        }
    }

    /** $conn->write(), but a broken backend connection stops $process: don't hand it back as healthy. */
    private function backendWrite(Duplex $conn, BuiltinProcess $process, string $data): void
    {
        try {
            $conn->write($data, self::IO_TIMEOUT);
        } catch (\Throwable $e) {
            $process->stop();

            throw $e instanceof IOException ? $e : new IOException("Classic: the backend's connection broke: {$e->getMessage()}");
        }
    }

    /** $conn->read(), but a broken backend connection stops $process: don't hand it back as healthy. */
    private function backendRead(Duplex $conn, BuiltinProcess $process): string
    {
        try {
            return $conn->read(65536, self::IO_TIMEOUT);
        } catch (\Throwable $e) {
            $process->stop();

            throw $e instanceof IOException ? $e : new IOException("Classic: the backend's connection broke: {$e->getMessage()}");
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
     * request's frame waits for every coroutine started inside it, so a `phasync::go()` here
     * would make the *client's* connection wait for this dial before it could be read for its
     * next request. Best-effort: a process stopped or reaped meanwhile just leaves
     * {@see BuiltinProcess::$conn} null, and the next exchange() dials for itself.
     */
    private function preconnect(BuiltinProcess $process): void
    {
        if (!$process->isRunning()) {
            return;
        }
        try {
            $conn = dial("tcp://127.0.0.1:{$process->port}", self::IO_TIMEOUT);
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
    private function relayResponse(ClientRequest $request, Duplex $conn, BuiltinProcess $process, \Closure $release): void
    {
        $buf = '';
        while (!\str_contains($buf, "\r\n\r\n")) {
            $chunk = $this->backendRead($conn, $process);
            if ('' === $chunk) {
                $process->stop();

                throw new IOException('Classic: the backend closed the connection before sending a response head');
            }
            $buf .= $chunk;
        }
        [$head, $rest] = \explode("\r\n\r\n", $buf, 2);
        $headLines = \explode("\r\n", $head);
        if (!\preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $headLines[0], $m)) {
            $process->stop();

            throw new IOException("Classic: not an HTTP response: {$headLines[0]}");
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

    private function relayChunked(ClientRequest $request, Duplex $conn, BuiltinProcess $process, string $buf, \Closure $release): void
    {
        while (true) {
            while (false === ($eol = \strpos($buf, "\r\n"))) {
                $buf .= $this->mustRead($conn, $process);
            }
            $sizeLine = \substr($buf, 0, $eol);
            $buf      = \substr($buf, $eol + 2);
            $size     = \hexdec(\trim(\explode(';', $sizeLine, 2)[0]));
            if (0 === $size) {
                // Trailer lines, if any, then a final blank line; trailers are not forwarded.
                for ($tries = 0; !\str_starts_with($buf, "\r\n") && $tries < 100; ++$tries) {
                    $eol = \strpos($buf, "\r\n");
                    if (false === $eol) {
                        $buf .= $this->mustRead($conn, $process);

                        continue;
                    }
                    $buf = \substr($buf, $eol + 2);
                }
                $release();

                return;
            }
            while (\strlen($buf) < $size + 2) {
                $buf .= $this->mustRead($conn, $process);
            }
            $request->write(\substr($buf, 0, $size), self::IO_TIMEOUT);
            $request->flush();
            $buf = \substr($buf, $size + 2); // the chunk's own trailing CRLF
        }
    }

    private function mustRead(Duplex $conn, BuiltinProcess $process): string
    {
        $chunk = $this->backendRead($conn, $process);
        if ('' === $chunk) {
            $process->stop();

            throw new IOException('Classic: the backend closed the connection mid-chunk');
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

    /** A free TCP port on 127.0.0.1: bind, read the port, release it (a benign race, as tests do). */
    private static function freePort(): int
    {
        $probe = \stream_socket_server('tcp://127.0.0.1:0');
        $addr  = \stream_socket_get_name($probe, false);
        \fclose($probe);

        return (int) \substr($addr, \strrpos($addr, ':') + 1);
    }
}
