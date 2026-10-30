<?php

namespace Swerve\Http;

use phasync;
use phasync\ShutdownException;
use phasync\IOException;
use phasync\Net\Duplex;
use phasync\TimeoutException;
use Psr\Log\LoggerInterface;
use Swerve\StaticFiles;
use Swerve\Util\Logger;

/**
 * One HTTP/1.1 client connection: reads a request head, runs the application's handler on
 * an {@see HttpClientRequest}, and repeats while the connection is kept alive.
 *
 * The connection's coroutine answers static files itself, and runs an application request in a
 * Scope and frame of its own. The connection goes on to its next request (pipelined requests
 * wait in the read buffer) once the response ended: in this coroutine when the request's code
 * ended too, else in a spare of the {@see RequestPool} while that code runs on (tail work).
 * The request body is read by the handler through its ClientRequest; what it leaves
 * unread is skipped afterwards, or the connection closes (see HttpClientRequest::run()).
 *
 * Requests are parsed strictly, since this is meant to face the internet: anything that could
 * frame a body two ways (request smuggling) or doesn't parse is refused with a 4xx/5xx and the
 * connection closes.
 *
 * @internal
 */
final class HttpConnection
{
    /** The request line and headers may not be larger than this (431). */
    public const MAX_HEAD = 65536;

    /** Header lines in a request head, or trailer fields after a chunked body (431). */
    public const MAX_HEADERS = 100;

    /** The request line may not be longer than this (414). */
    private const MAX_REQUEST_LINE = 8192;

    /** A chunk-size line, with extensions, or a trailer field line (400). */
    public const MAX_CHUNK_LINE = 4096;

    /**
     * Seconds from a request head's first byte to its end (408); also how long a new
     * connection may wait before sending anything (then closed silently). Bounds slowloris.
     */
    private const HEAD_TIMEOUT = 10.0;

    /** Seconds a kept-alive connection may stay idle, waiting for its next request (as in Node). */
    private const KEEP_ALIVE_TIMEOUT = 5.0;

    /** Seconds each wait may last while writing a response. */
    public const IO_TIMEOUT = 60.0;

    /**
     * Seconds a client may keep the application waiting for its request body, counting only
     * the waits, not the application's own time: BODY_TIMEOUT to start with, one more for each
     * BODY_MIN_RATE bytes it sends, never more than IO_TIMEOUT saved up. A client sending a
     * byte now and then would otherwise hold the connection, and the application, for days.
     */
    private const BODY_TIMEOUT  = 10.0;
    private const BODY_MIN_RATE = 1024;

    /** Seconds to keep reading (and discarding) before closing, see serve(). */
    private const LINGER_TIMEOUT = 2.0;


    /** An unread request body rest up to this is skipped to keep the connection; a larger one closes it. */
    public const DISCARD_LIMIT = 65536;

    /**
     * Seconds skipping an unread request body's rest may take in all. Each wait is bounded by
     * the client's allowance, but a client sending a byte now and then would otherwise hold the
     * connection for days.
     */
    public const DISCARD_TIMEOUT = 5.0;

    /** Control characters, which a header value may not contain, but horizontal tab (RFC 9110 5.5). */
    public const CTL = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";

    /** The characters of a token (RFC 9110 5.6.2), such as a method. */
    public const TOKEN = "!#$%&'*+-.^_`|~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";

    /** Not allowed in a request target: control characters (space is the separator) and '#'. */
    private const NOT_IN_TARGET = "#\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";

    /**
     * The characters of a Host value: host (reg-name or IP literal) and port (RFC 9110 7.2).
     * Never '/', '?', '#' or '@', so it can't end the authority early when the URI is built.
     */
    public const HOST = "!$%&'()*+,-.0123456789:;=ABCDEFGHIJKLMNOPQRSTUVWXYZ[]_abcdefghijklmnopqrstuvwxyz~";

    /** Bytes per socket read. */
    private const READ_SIZE = 65536;

    /**
     * Request-body reads in a row without a pause, see readBody(); also pipelined requests in a
     * row, see serve(). Then the connection lets the worker's other coroutines run.
     */
    private const BURST = 8;

    /** What the server closes first to make room, see reclaim() and $reclaimable. */
    public const ANSWERED   = 1;
    public const NEW        = 2;
    public const KEPT_ALIVE = 3;
    public const BODY       = 4;

    private static int $dateTime = 0;
    private static string $date  = '';

    /**
     * PHP's shutdown began, after an exit() (in a request, or the worker's at its drain
     * deadline) or a fatal error: the suspended coroutines are destroyed, which runs their
     * finally blocks, and the event loop can no longer take a raised flag or a suspension;
     * trying turns the exit into a PHP fatal error (exit 255). Set by a shutdown function
     * (Swerve::accept()'s), which PHP calls before it destroys them.
     */
    public static bool $exiting = false;

    /**
     * Data read from the connection; the unconsumed part is $buffer from $offset on: the next
     * request head, or the start of the current request's body. An offset instead of cutting
     * the string on every read avoids copying the rest of the buffer each time.
     */
    private string $buffer = '';
    private int $offset    = 0;

    /** A request was answered and the connection kept: the next one gets KEEP_ALIVE_TIMEOUT. */
    private bool $keptAlive = false;

    /**
     * Before closing, stop writing and read what the client still sends. Closing with unread
     * data makes the kernel send a reset, which can destroy the response before the client
     * reads it.
     */
    public bool $linger = false;

    /**
     * While this connection's coroutine waits, the server may close the connection to make
     * room, see reclaim(): ANSWERED while it lingers, or skips an unread body after the
     * response, NEW or KEPT_ALIVE while it waits for a request head, BODY while it waits for
     * more of a request body; 0 while it runs, or waits for the application or to write, or
     * once upgraded.
     */
    public int $reclaimable = 0;

    /** A 101 was sent: the connection carries the application's protocol, to which no HTTP limit or timeout applies. */
    public bool $upgraded = false;

    /** While skipping an unread request body: when that must be done by. */
    public ?float $discardUntil = null;

    /** Seconds the client may still keep us waiting for the current request body, see BODY_TIMEOUT. */
    private float $bodyAllowance = 0.0;

    /** Request-body reads in a row that got all they asked for, see readBody(). */
    private int $fullReads = 0;

    /** See current(). */
    private ?HttpClientRequest $current = null;

    /** Answered from here without the application, in this connection's coroutine; may be replaced between requests. */
    public ?StaticFiles $files = null;

    /**
     * Goes on with the connection in another coroutine, from the response end of the request
     * given, while that request's code runs on (see responded()); null: serve() does.
     *
     * @var ?\Closure(HttpClientRequest): void
     */
    public ?\Closure $onMove = null;

    /** Connections gone on in a spare coroutine so far, for the tests. */
    public static int $moves = 0;

    /** Reserved for the current application request, until its response ended. */
    private ?RequestPool $spare = null;

    /**
     * @param \Closure(\Swerve\ClientRequest): void $handler     the application's; may be replaced between requests
     * @param ?Logger                               $access      the access log, if on
     */
    public function __construct(
        public readonly Duplex $io,
        public \Closure $handler,
        public readonly LoggerInterface $logger,
        public readonly ?Logger $access,
    ) {
    }

    /** The Date header's value: formatted once a second. */
    public static function date(): string
    {
        if (self::$dateTime !== ($time = \time())) {
            self::$dateTime = $time;
            self::$date     = \gmdate('D, d M Y H:i:s', $time) . ' GMT';
        }

        return self::$date;
    }

    /**
     * Serve the connection's requests until it closes. With $one, serve a single request: then
     * true when the connection is kept alive (left open, see pending() for its next request),
     * false when it is closed. Null when the connection went on in another coroutine (see
     * $onMove), while a request ran on here. With $resume, go on from that request's response.
     */
    public function serve(bool $one = false, ?HttpClientRequest $resume = null): ?bool
    {
        $idle = $moved = false;
        try {
            $first     = !$this->keptAlive && null === $resume;
            $pipelined = 0;
            $keepAlive = false;
            do {
                if (null !== $resume) {
                    $request   = $resume;
                    $resume    = null;
                    $keepAlive = $this->after($request);
                } else {
                    $request = $this->readRequest($first);
                    if (null === $request) {
                        break;
                    }
                    $first         = false;
                    $this->current = $request;
                    $file          = $this->files?->route($request);
                    if (null !== $file) {
                        // A static file touches no state of the application: no Scope
                        $keepAlive = $request->run($file);
                        $request->reusable();
                        $request->recycle();
                    } elseif (null === $keepAlive = $this->application($request)) {
                        // Another coroutine has the connection: nothing here is ours any more
                        $moved = true;

                        return null;
                    }
                }
                $this->current   = null;
                $this->keptAlive = $keepAlive;
                unset($request);
                // With the next request already buffered (pipelined), and the response written
                // without waiting, nothing would suspend this coroutine: a client sending many
                // requests at once would have the worker to itself until all are answered
                if ($keepAlive && \strlen($this->buffer) !== $this->offset && 0 === ++$pipelined % self::BURST) {
                    phasync::sleep();
                }
            } while ($keepAlive && !$one);
            // Only what this worker has read is lost by a handover: the kernel's bytes go with the socket
            $idle = $one && $keepAlive;
        } catch (HttpError $e) {
            $this->refuse($e->getCode(), $e->getMessage());
        } catch (ShutdownException) {
            $this->linger = false; // the worker stops: close at once, no waiting for the client's end
        } catch (\Throwable $e) {
            $this->logger->error('A connection failed: {exception}', ['exception' => $e]);
        } finally {
            if (!$idle && !$moved) {
                $this->end();
            }
        }

        return $idle;
    }

    /**
     * Run an application request here, to its end, tail work included: returns whether the
     * connection is kept alive, or null when the connection went on in a spare coroutine while
     * the request ran on (see responded()).
     */
    private function application(HttpClientRequest $request): ?bool
    {
        $request->hold(); // by this coroutine too: the connection may go on, and let go, first
        $this->spare = RequestPool::reserve();
        try {
            // Each request is a Scope of its own, its identity: state kept per request hangs on a
            // WeakMap keyed by Scope::getCurrent(), and phasync::onScopeChange() hooks swap process
            // state (a locale) as the event loop moves between requests. And a frame of its own,
            // which waits for the coroutines the request starts
            (new \Scope())->run(fn () => phasync::run($this->runRequest(...), $request, $this->handler));
        } catch (\Throwable $e) {
            // Its coroutines failed or were stopped after the response
            if (!$e instanceof ShutdownException) {
                $this->logger->error('A coroutine of {request} failed: {exception}', ['request' => $request->getMethod() . ' ' . $request->getTarget(), 'exception' => $e]);
            }
        } finally {
            $this->responded($request, false, false); // when it failed before it responded
            ($request->onEnd)?->__invoke();
        }
        if ($request->carriedOn) {
            $request->recycle();

            return null;
        }
        $request->carriedOn = true;
        $request->recycle();

        return $this->after($request);
    }

    /** After $request's response: whether the connection is kept alive. */
    private function after(HttpClientRequest $request): bool
    {
        $keepAlive = $request->responded;
        $request->recycle();

        return $keepAlive;
    }

    /**
     * A request in its Scope and frame. A coroutine of it that fails with nobody awaiting it is
     * logged, and the request goes on.
     */
    private function runRequest(HttpClientRequest $request, \Closure $handler): bool
    {
        phasync::frame()->setExceptionHandler(fn (\Throwable $e) => $this->logger->error('A coroutine of {request} failed: {exception}', ['request' => $request->getMethod() . ' ' . $request->getTarget(), 'exception' => $e]));
        $keepAlive = $request->run($handler);
        // Its own coroutines may run on (tail work)
        $this->responded($request, $keepAlive, \count(phasync::frame()) > 1);

        return $keepAlive;
    }

    /**
     * $request's response ended. When its code runs on, the spare goes on with the connection
     * if this coroutine suspends first; else this one does, once the request's code ended.
     */
    private function responded(HttpClientRequest $request, bool $keepAlive, bool $runsOn): void
    {
        if (null !== $request->responded) {
            return;
        }
        $request->responded = $keepAlive;
        $spare              = $this->spare;
        $this->spare        = null;
        if (!$runsOn || null === $spare) { // null: a static file, answered here
            $spare?->release();

            return;
        }
        $request->hold();
        $spare->give(function () use ($request): void {
            if (!$request->carriedOn) {
                $request->carriedOn = true;
                ++self::$moves;
                ($this->onMove ?? fn (HttpClientRequest $request) => $this->serve(resume: $request))($request);
            }
            $request->recycle();
        });
    }

    /**
     * $request's response is complete while its handler runs on (HttpClientRequest::finish()):
     * the connection goes on without it.
     *
     * @internal HttpClientRequest
     */
    public function finished(HttpClientRequest $request, bool $keepAlive): void
    {
        if (!$this->upgraded && $this->current === $request) {
            $request->detach();
            $this->responded($request, $keepAlive, true);
        }
    }

    /** swerve's own ending of the connection: shielded, so it completes while the worker stops. */
    private function end(): void
    {
        phasync::shielded(function () {
            if ($this->linger && !self::$exiting) {
                $this->io->end();
                $deadline          = \microtime(true) + self::LINGER_TIMEOUT;
                $this->reclaimable = self::ANSWERED;
                try {
                    while ('' !== $this->io->read(self::READ_SIZE, \max(0.0, $deadline - \microtime(true))) && \microtime(true) < $deadline) {
                    }
                } catch (IOException|TimeoutException) {
                }
            }
            $this->reclaimable = 0;
            // While PHP shuts down nothing can be raised or woken: the descriptor goes with the process
            if (!self::$exiting) {
                $this->io->close();
            }
        });
    }

    /**
     * Close the connection if it waits in the given state (see $reclaimable), to make room for
     * another. Returns whether it will close.
     */
    public function reclaim(int $state): bool
    {
        if ($state !== $this->reclaimable) {
            return false;
        }
        $this->reclaimable = 0;
        $this->io->close(); // wakes this connection's own coroutine, which finds the end of the stream

        return true;
    }

    /** No request bytes are buffered. */
    /** The request being served, until its handler returns: still set when exit() cut it short. */
    public function current(): ?HttpClientRequest
    {
        return $this->current;
    }

    /** The client's next request has arrived: read already (pipelined), or waiting in the kernel. */
    public function pending(): bool
    {
        return !$this->bufferEmpty() || $this->io->pending();
    }

    public function bufferEmpty(): bool
    {
        return \strlen($this->buffer) === $this->offset;
    }

    /**
     * Up to $max bytes of the current request's body, at least 1: the caller never asks for
     * more than the body has left. From the buffer if it has any, otherwise from the client,
     * reading up to $readable bytes (at most READ_SIZE) into the buffer: however little the
     * application asks for, one read takes what the body offers, but never more than the
     * caller says is the body's, so nothing beyond it is read ahead.
     *
     * Only BURST reads in a row that got all they asked for are made without a pause, though:
     * a fast client would otherwise have the worker to itself until its body ends.
     *
     * @param ?float $timeout seconds to wait for the client; null: its allowance (see BODY_TIMEOUT)
     *
     * @throws IOException|TimeoutException when the client went away or stalled
     */
    public function readBody(int $max, int $readable, ?float $timeout = null): string
    {
        $have = \strlen($this->buffer) - $this->offset;
        if (0 === $have) {
            $size = \min($readable, self::READ_SIZE);
            if (0 !== $this->fullReads && 0 === $this->fullReads % self::BURST) {
                phasync::sleep();
            }
            $chunk           = $this->receiveBody($size, $timeout);
            $this->fullReads = \strlen($chunk) === $size ? $this->fullReads + 1 : 0;
            if (\strlen($chunk) <= $max) {
                return $chunk;
            }
            $this->buffer = $chunk; // the rest is for the next read
            $this->offset = 0;
            $have         = \strlen($chunk);
        }
        if (0 === $this->offset && $max >= $have) {
            $chunk        = $this->buffer; // the whole buffer: no copy
            $this->buffer = '';

            return $chunk;
        }
        $chunk         = \substr($this->buffer, $this->offset, \min($max, $have));
        $this->offset += \strlen($chunk);
        if ($this->offset === \strlen($this->buffer)) {
            $this->buffer = '';
            $this->offset = 0;
        }

        return $chunk;
    }

    /**
     * The next bytes of a request body from the client, within its allowance (see BODY_TIMEOUT)
     * and, when skipping an unread body, by its deadline.
     *
     * @throws IOException|TimeoutException
     */
    private function receiveBody(int $size, ?float $timeout): string
    {
        $start = \microtime(true);
        $wait  = null === $timeout ? $this->bodyAllowance : \min($timeout, $this->bodyAllowance);
        if (null === $this->discardUntil) {
            $this->reclaimable = self::BODY;
        } else {
            $this->reclaimable = self::ANSWERED;
            $wait              = \min($wait, $this->discardUntil - $start);
        }
        try {
            $chunk = $this->io->read($size, \max(0.0, $wait));
        } finally {
            $this->reclaimable    = 0;
            $this->bodyAllowance -= \microtime(true) - $start;
        }
        if ('' === $chunk) {
            throw new IOException('Connection closed by the client');
        }
        $this->bodyAllowance = \min(self::IO_TIMEOUT, $this->bodyAllowance + \strlen($chunk) / self::BODY_MIN_RATE);

        return $chunk;
    }

    /**
     * The bytes before the next CRLF, consuming the CRLF too. readLine(0) requires the next
     * two bytes to be exactly CRLF.
     *
     * @throws HttpError when the line is longer than $max
     * @throws IOException|TimeoutException when the client went away or stalled
     */
    public function readLine(int $max): string
    {
        $from = $this->offset;
        while (false === ($end = \strpos($this->buffer, "\r\n", $from))) {
            $have = \strlen($this->buffer) - $this->offset;
            if ($have > $max + 1) { // +1: a trailing CR may be half of the CRLF
                throw new HttpError('Bad Request', 400);
            }
            $this->fill(null);
            $from = \max(0, $have - 1); // resume where the search stopped, never rescan
        }
        if ($end - $this->offset > $max) {
            throw new HttpError('Bad Request', 400);
        }
        $line         = \substr($this->buffer, $this->offset, $end - $this->offset);
        $this->offset = $end + 2;
        if ($this->offset === \strlen($this->buffer)) {
            $this->buffer = '';
            $this->offset = 0;
        }

        return $line;
    }

    /**
     * An upgraded connection's next bytes: the buffer first (bytes the client sent right behind
     * the upgrade request), then the client, waiting without a timeout. '' at the end: the
     * client closed its side.
     */
    public function readRaw(int $max, ?float $timeout = null): string
    {
        if (\strlen($this->buffer) !== $this->offset) {
            return $this->readBody($max, \PHP_INT_MAX); // from the buffer only
        }

        return $this->io->read($max, $timeout);
    }

    /**
     * Read more from the client into the buffer, waiting up to $timeout seconds (null: a wait
     * for more of a request body, see receiveBody()). Only called when the unconsumed data is
     * incomplete (part of a head or a line), so moving it to the start of the buffer copies at
     * most about MAX_HEAD bytes.
     *
     * @throws IOException when the client closed the connection
     * @throws TimeoutException
     */
    private function fill(?float $timeout): void
    {
        if ($this->offset) {
            $this->buffer = \substr($this->buffer, $this->offset);
            $this->offset = 0;
        }
        if (null === $timeout) {
            $chunk = $this->receiveBody(self::READ_SIZE, null);
        } else {
            $chunk = $this->io->read(self::READ_SIZE, $timeout);
            if ('' === $chunk) {
                throw new IOException('Connection closed by the client');
            }
        }
        // Appended in place: a head arriving a byte at a time is not copied whole each time
        $this->buffer .= $chunk;
    }

    /**
     * Read the next request: its head is parsed, and its body is left for the handler.
     * Null when the connection is to close without an answer.
     *
     * @param bool $first the connection's first request: waiting for it is bounded by
     *                    HEAD_TIMEOUT, not by KEEP_ALIVE_TIMEOUT
     *
     * @throws HttpError when the request is refused
     */
    private function readRequest(bool $first): ?HttpClientRequest
    {
        $this->reclaimable = $first ? self::NEW : self::KEPT_ALIVE;

        // The request head: request line and headers, up to the empty line. The idle wait for
        // its first byte has its own timeout; from then on the whole head must arrive within
        // HEAD_TIMEOUT (slowloris). When the head arrives whole in one read, which is the
        // normal case, no clock is read at all. Empty lines before a request are ignored (RFC
        // 9112 2.2; some clients send one after a body): with only those, the connection is idle.
        $this->offset += \strspn($this->buffer, "\r\n", $this->offset);
        if (\strlen($this->buffer) === $this->offset) {
            $until = null;
            try {
                while (true) {
                    $this->buffer = '';
                    $this->offset = 0;
                    $this->fill(null === $until ? ($first ? self::HEAD_TIMEOUT : self::KEEP_ALIVE_TIMEOUT) : $until - \microtime(true));
                    if (\strlen($this->buffer) !== ($this->offset = \strspn($this->buffer, "\r\n"))) {
                        break;
                    }
                    // Only empty lines: still idle, but not for (much) longer in all
                    $until ??= \microtime(true) + ($first ? self::HEAD_TIMEOUT : self::KEEP_ALIVE_TIMEOUT);
                }
            } catch (IOException|TimeoutException) {
                return null; // idle too long, or closed: nothing to answer
            }
        }
        $from     = $this->offset;
        $deadline = null;
        while (false === ($end = \strpos($this->buffer, "\r\n\r\n", $from))) {
            $have = \strlen($this->buffer) - $this->offset;
            // What can't become a request line is answered now, not at the timeout: a request
            // line starts with a token method and a space (a TLS ClientHello starts with 0x16)
            $method = \strspn($this->buffer, self::TOKEN, $this->offset);
            if ($method < $have && (0 === $method || ' ' !== $this->buffer[$this->offset + $method])) {
                throw new HttpError('Bad Request', 400);
            }
            if ($have > self::MAX_HEAD) {
                throw new HttpError('Request Header Fields Too Large', 431);
            }
            $deadline ??= \microtime(true) + self::HEAD_TIMEOUT;
            try {
                $left = $deadline - \microtime(true);
                if ($left <= 0) {
                    throw new TimeoutException('The request head took too long');
                }
                $this->fill($left);
            } catch (TimeoutException) {
                throw new HttpError('Request Timeout', 408);
            } catch (IOException) {
                return null;
            }
            $from = \max($this->offset, $have - 3);
        }
        if ($end - $this->offset > self::MAX_HEAD) {
            throw new HttpError('Request Header Fields Too Large', 431);
        }
        $this->reclaimable   = 0;
        $this->bodyAllowance = self::BODY_TIMEOUT;
        $this->fullReads     = 0;
        $head                = \substr($this->buffer, $this->offset, $end - $this->offset);
        $this->offset        = $end + 4;
        if ($this->offset === \strlen($this->buffer)) {
            $this->buffer = '';
            $this->offset = 0;
        }

        $lines = \explode("\r\n", $head);
        if (\count($lines) - 1 > self::MAX_HEADERS) {
            throw new HttpError('Request Header Fields Too Large', 431);
        }
        if (\strlen($lines[0]) > self::MAX_REQUEST_LINE) {
            throw new HttpError('URI Too Long', 414);
        }
        // Exactly one space between the parts, a token method, no control character in the
        // target. Plain string functions: a regex costs more here, on every request.
        $parts = \explode(' ', $lines[0]);
        if (3 !== \count($parts)) {
            throw new HttpError('Bad Request', 400);
        }
        [$method, $target, $protocol] = $parts;
        if ('HTTP/1.1' === $protocol) {
            $version = '1.1';
        } elseif ('HTTP/1.0' === $protocol) {
            $version = '1.0';
        } else {
            throw 1 === \preg_match('#^HTTP/[0-9]\.[0-9]$#D', $protocol)
                ? new HttpError('HTTP Version Not Supported', 505)
                : new HttpError('Bad Request', 400);
        }
        if ('' === $method || \strspn($method, self::TOKEN) !== \strlen($method) || \strcspn($target, self::NOT_IN_TARGET) !== \strlen($target)) {
            throw new HttpError('Bad Request', 400);
        }
        if ('CONNECT' === $method) {
            throw new HttpError('Not Implemented', 501);
        }
        if ('/' !== ($target[0] ?? '') && !('*' === $target && 'OPTIONS' === $method)
            && 0 !== \strncasecmp($target, 'http://', 7) && 0 !== \strncasecmp($target, 'https://', 8)) {
            throw new HttpError('Bad Request', 400);
        }

        // Framing is decided on exact (lower-cased) names only; a line such as
        // "Transfer-Encoding : chunked" never counts: its name is not a token, which is refused.
        $headers    = []; // lower-case name => values
        $hosts      = 0;
        $host       = '';
        $length     = null;
        $te         = null;
        $connection = '';
        $expect     = null;
        for ($i = 1, $n = \count($lines); $i < $n; ++$i) {
            $colon = \strpos($lines[$i], ':');
            if (false === $colon) {
                throw new HttpError('Bad Request', 400);
            }
            $name  = \substr($lines[$i], 0, $colon);
            $value = \trim(\substr($lines[$i], $colon + 1), " \t");
            // RFC 9112: a name is a token (which rejects whitespace before the colon, and obs-fold
            // continuation lines); a value has no control characters but horizontal tab
            if ('' === $name || \strspn($name, self::TOKEN) !== \strlen($name) || \strcspn($value, self::CTL) !== \strlen($value)) {
                throw new HttpError('Bad Request', 400);
            }
            $lower             = \strtolower($name);
            $headers[$lower][] = $value;
            switch ($lower) {
                case 'host':
                    ++$hosts;
                    $host = $value;
                    break;
                case 'content-length':
                    // One plain decimal number only: rejects duplicates (even equal ones),
                    // signs, lists, hex, exponents, empty values and overflow
                    if (null !== $length || !\ctype_digit($value) || \strlen($value) > 18) {
                        throw new HttpError('Bad Request', 400);
                    }
                    $length = (int) $value;
                    break;
                case 'transfer-encoding':
                    if (null !== $te) {
                        throw new HttpError('Bad Request', 400);
                    }
                    $te = $value;
                    break;
                case 'connection':
                    $connection = '' === $connection ? \strtolower($value) : "$connection," . \strtolower($value);
                    break;
                case 'expect':
                    $expect = $value;
                    break;
            }
        }
        if (('1.1' === $version ? 1 !== $hosts : $hosts > 1) || \strspn($host, self::HOST) !== \strlen($host)) {
            throw new HttpError('Bad Request', 400);
        }
        if (null !== $te) {
            // Two framings, or chunked where HTTP/1.0 has none: request smuggling
            if ('1.0' === $version || null !== $length) {
                throw new HttpError('Bad Request', 400);
            }
            if (0 !== \strcasecmp($te, 'chunked')) {
                throw new HttpError('Not Implemented', 501);
            }
        }
        // close wins, in either version (RFC 9112 9.3); HTTP/1.0 persists only when asked to
        if ('' === $connection) {
            $keepAlive = '1.1' === $version;
        } else {
            $options   = 'close' === $connection || 'keep-alive' === $connection ? [$connection] : \array_map('trim', \explode(',', $connection));
            $keepAlive = !\in_array('close', $options, true) && ('1.1' === $version || \in_array('keep-alive', $options, true));
        }

        $continue = false;
        if (null !== $expect && '1.1' === $version) {
            if (0 !== \strcasecmp($expect, '100-continue')) {
                throw new HttpError('Expectation Failed', 417);
            }
            $continue = null !== $te || $length > 0;
        }

        // An absolute-form target (RFC 9112 3.2.2): its authority is the host, and replaces the
        // Host header. The authority gets the Host header's check. The target stays as sent.
        if ('/' !== $target[0] && '*' !== $target) {
            $start = 0 === \strncasecmp($target, 'https://', 8) ? 8 : 7;
            $end   = \strcspn($target, '/?', $start);
            $host  = \substr($target, $start, $end);
            if ('' === $host || \strspn($host, self::HOST) !== $end) {
                throw new HttpError('Bad Request', 400);
            }
            $headers['host'] = [$host];
        }

        return HttpClientRequest::create($this, $method, $target, $version, $headers, null !== $te ? null : ($length ?? 0), $continue, $keepAlive);
    }

    /**
     * Answer a refused request or a failed handler with $status and its error page, in one
     * best-effort write that never waits, and close. $request, when the head was read, says
     * whether the page is HTML and whether it has a body (not for HEAD). With $linger, the close
     * waits a moment for the client's end, so that the answer isn't destroyed by a reset; a
     * stopping worker doesn't wait.
     */
    public function refuse(int $status, string $reason, bool $linger = true, ?HttpClientRequest $request = null): void
    {
        [$type, $body] = ErrorPage::render($status, $reason, null !== $request && ErrorPage::acceptsHtml($request->getRequestHeaders()['accept'] ?? []));
        $length        = \strlen($body);
        if ('HEAD' === $request?->getMethod()) {
            $body = '';
        }
        try {
            $this->io->write("HTTP/1.1 $status $reason\r\nContent-Type: $type\r\nContent-Length: $length\r\nConnection: close\r\nDate: " . self::date() . "\r\nServer: Swerve\r\n\r\n$body", 0.0);
        } catch (IOException|TimeoutException) {
        }
        $this->linger = $linger;
    }
}
