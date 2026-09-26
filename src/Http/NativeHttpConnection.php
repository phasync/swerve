<?php

namespace Swerve\Http;

use phasync;
use phasync\CancelledException;
use phasync\IOException;
use phasync\TimeoutException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Swerve\Util\LoggingContext;

/**
 * One HTTP/1.1 client connection in HTTP mode: reads a request, calls the application,
 * writes the response, and repeats while the connection is kept alive.
 *
 * Requests on a connection are handled one after another in this connection's coroutine
 * (pipelined requests wait in the read buffer), so there is no coroutine per request. This
 * coroutine writes every response. The request body is read by whoever reads it: from the end
 * of a request head until that body ends, only its reader touches the socket's reading side,
 * in whatever coroutine it runs, also after the response (see settle()). The next request
 * starts once the response is written and its request body was read to its end or released.
 * Request bodies read after the response, upgrade requests and 101: see RequestBody.
 *
 * Nothing is buffered by default. The request body streams to the application (RequestBody),
 * and the response body streams to the socket as it is read from its StreamInterface: the
 * head goes out together with the body's first piece in one write, so a small response is one
 * syscall, and an event stream's headers arrive with its first event. With $bufferResponses,
 * each response body is read whole first (up to RESPONSE_BUFFER_LIMIT) and sent with a
 * Content-Length in one write.
 *
 * Requests are parsed strictly, since this is meant to face the internet: anything that could
 * frame a body two ways (request smuggling) or doesn't parse is refused with a 4xx/5xx and the
 * connection closes.
 *
 * The request's getParsedBody() is always null, since the body is never read for the
 * application: parse it from the body stream, as Slim's BodyParsingMiddleware does. A response
 * body must be readable: Slim's NonBufferedBody echoes to PHP's output instead, which only a
 * classic SAPI sends to the client, so it gets 500 here.
 */
final class NativeHttpConnection
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

    /** Seconds a kept-alive connection may stay idle, waiting for its next request. */
    private const KEEP_ALIVE_TIMEOUT = 30.0;

    /** Seconds each wait may last while reading a request body or writing a response. */
    private const IO_TIMEOUT = 60.0;

    /**
     * Seconds a client may keep the application waiting for its request body, counting only
     * the waits, not the application's own time: BODY_TIMEOUT to start with, one more for each
     * BODY_MIN_RATE bytes it sends, never more than IO_TIMEOUT saved up. A client sending a
     * byte now and then would otherwise hold the connection, and the application, for days.
     */
    private const BODY_TIMEOUT = 10.0;
    private const BODY_MIN_RATE = 1024;

    /** Seconds to keep reading (and discarding) after an error response, see serve(). */
    private const LINGER_TIMEOUT = 2.0;

    /** While draining, how long a new connection may take to start its request, see drain(). */
    private const DRAIN_NEW_WAIT = 1.0;

    /**
     * An unread request body rest up to this is skipped to keep the connection, or read into the
     * body for the application when it still holds it (see settle()); a larger one closes it.
     */
    private const DISCARD_LIMIT = 65536;

    /**
     * Seconds skipping a released body's rest may take in all. Each wait is bounded by
     * IO_TIMEOUT, but a client sending a byte now and then would otherwise hold the connection
     * for days. Not for a body the application still holds (see settle()): that one may still
     * be read, and gets the client's allowance (BODY_TIMEOUT), whenever it is read.
     */
    private const DISCARD_TIMEOUT = 5.0;

    /** The default largest request body (413), see $maxBodySize. */
    public const MAX_BODY = 8388608;

    /** The characters of a token (RFC 9110 5.6.2), such as a method. */
    /** Control characters, which a header value may not contain, but horizontal tab (RFC 9110 5.5). */
    private const CTL = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";

    private const TOKEN = "!#$%&'*+-.^_`|~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";

    /** Not allowed in a request target: control characters (space is the separator) and '#'. */
    private const NOT_IN_TARGET = "#\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";

    /**
     * The characters of a Host value: host (reg-name or IP literal) and port (RFC 9110 7.2).
     * Never '/', '?', '#' or '@', so it can't end the authority early when the URI is built.
     */
    private const HOST = "!$%&'()*+,-.0123456789:;=ABCDEFGHIJKLMNOPQRSTUVWXYZ[]_abcdefghijklmnopqrstuvwxyz~";

    /** Bytes per socket read and per response-body read. */
    private const READ_SIZE = 65536;

    /**
     * Request-body reads in a row without waiting on the event loop, see readBody(); also
     * pipelined requests in a row, see serve(). Then the connection lets the worker's other
     * coroutines run.
     */
    private const BURST = 8;

    /** What the server closes first to make room, see reclaim() and $reclaimable. */
    public const ANSWERED   = 1;
    public const NEW        = 2;
    public const KEPT_ALIVE = 3;
    public const BODY       = 4;

    /** With $bufferResponses: a response body larger than this is streamed after all. */
    private const RESPONSE_BUFFER_LIMIT = 8388608;

    private static int $dateTime = 0;
    private static string $date  = '';

    /**
     * PHP's shutdown began, after an exit() (in a request, or the worker's at its drain
     * deadline) or a fatal error: the suspended coroutines are destroyed, which runs their
     * finally blocks, and the event loop can no longer take a raised flag or a suspension;
     * trying turns the exit into a PHP fatal error (exit 255). Set by a shutdown function
     * (NativeHttpServer::listen()), which PHP calls before it destroys them.
     */
    public static bool $exiting = false;

    /**
     * Data read from the socket; the unconsumed part is $buffer from $offset on: the next
     * request head, or the start of the current request's body. An offset instead of cutting
     * the string on every read avoids copying the rest of the buffer each time.
     */
    private string $buffer = '';
    private int $offset    = 0;

    /**
     * The socket failed or stalled while reading a body or writing a response. Recorded
     * because the application may catch or wrap our exception: the connection must still know
     * the client is gone, and not answer or log it as an application error.
     */
    private ?\Throwable $ioError = null;

    /** The current response's head was (or was being) written: an error can no longer be answered. */
    private bool $headSent = false;

    /**
     * Before closing, stop writing and read what the client still sends. Closing with unread
     * data makes the kernel send a reset, which can destroy the response before the client
     * reads it.
     */
    private bool $linger = false;

    /**
     * While this connection's coroutine waits, the server may close the connection to make
     * room, see reclaim(): ANSWERED while it lingers, or skips or absorbs an unread body after
     * the response, NEW or KEPT_ALIVE while it waits for a request head, BODY while it waits for
     * more of a request body; 0 while it runs, or waits for the application or to write, or
     * once upgraded.
     */
    private int $reclaimable = 0;

    /** The current request's method and target, for the log. */
    private string $request = '';

    /**
     * The server is draining: this connection closes after its current request, see drain().
     * Read by RequestBody: a tunnel's input ends.
     */
    public bool $draining = false;

    /** While skipping an unread request body: when that must be done by. */
    private ?float $discardUntil = null;

    /** Seconds the client may still keep us waiting for the current request body, see BODY_TIMEOUT. */
    private float $bodyAllowance = 0.0;

    /** Request-body reads in a row that got all they asked for, see readBody(). */
    private int $fullReads = 0;

    private readonly string $remoteAddr;
    private readonly int $remotePort;

    /** The current request body, as far as the connection knows, see settle(). */
    private const BODY_OPEN     = 0;
    private const BODY_DONE     = 1;
    private const BODY_FAILED   = 2;
    private const BODY_RELEASED = 3;

    /** The current request body's state, see settle(); reset for each request. */
    private int $bodyState = self::BODY_OPEN;

    /** A request body released unfinished, kept alive until its rest is skipped, see settle(). */
    private ?RequestBody $released = null;

    /** settle() waits on the flag $this: the body's reports raise it only then. */
    private bool $settling = false;

    /**
     * The coroutine of a read of the request body waiting on the socket (awaitBody(), readRaw()):
     * the socket is never closed under it (serve()), settle() leaves the body to it, and in a
     * tunnel, drain() wakes it. Also a wait that began before the 101, for the upgrade request's
     * own framed body.
     */
    private ?\Fiber $reader = null;

    /**
     * A 101 was sent: the socket carries the application's protocol, to which no HTTP limit or
     * timeout applies (see tunnel()).
     */
    private bool $upgraded = false;

    /** serve() ended. Read by RequestBody: later reads throw (a switched body's give EOF). */
    public bool $closed = false;

    /** The coroutine running the application's handle() for the current request; read by RequestBody. */
    public ?\Fiber $fiber = null;

    /**
     * @param resource $socket      a connected, non-blocking stream
     * @param int      $maxBodySize a larger request body gets 413, by its Content-Length before
     *                              the application runs, or when a chunk would exceed it
     */
    public function __construct(
        private readonly mixed $socket,
        string $peer,
        private readonly RequestHandlerInterface $handler,
        private readonly LoggerInterface $logger,
        private readonly bool $bufferResponses = false,
        private readonly int $maxBodySize = self::MAX_BODY,
    ) {
        // Without PHP's own 8 KiB read buffer, a read asks the kernel for what it asks for:
        // READ_SIZE, or no more than the body has left, so nothing is read ahead
        \stream_set_read_buffer($socket, 0);
        $colon            = (int) \strrpos($peer, ':');
        $this->remoteAddr = \substr($peer, 0, $colon);
        $this->remotePort = (int) \substr($peer, $colon + 1);
    }

    public function serve(): void
    {
        try {
            $first     = true;
            $pipelined = 0;
            do {
                $keepAlive = $this->handleRequest($first);
                $first     = false;
                // With the next request already buffered (pipelined), and the response written
                // without waiting, nothing would suspend this coroutine: a client sending many
                // requests at once would have the worker to itself until all are answered
                if ($keepAlive && \strlen($this->buffer) !== $this->offset && 0 === ++$pipelined % self::BURST) {
                    phasync::sleep();
                }
            } while ($keepAlive && !$this->draining);
            if ($keepAlive) {
                // Drained after the response said keep-alive: close as if it had said close
                $this->linger = true;
            }
        } catch (HttpError $e) {
            // After the head was sent (an echoed request body turned out malformed), just close
            if (!$this->headSent) {
                $this->writeError($e->getCode(), $e->getMessage());
            }
        } catch (\Throwable $e) {
            if (null === $this->ioError) {
                // Not a lost or stalled client: a real error
                $this->logger->error('{request} failed: {exception}', ['request' => $this->request, 'exception' => $e]);
                if (!$this->headSent) {
                    $this->writeError(500, 'Internal Server Error');
                }
                // Head already sent: closing is all we can do. A chunked body then lacks its
                // last chunk, so the client sees the response is truncated.
            }
        } finally {
            $this->closed   = true; // a body the application still holds: its reads throw (a tunnel's: EOF)
            $this->released = null; // released while an exception unwound handleRequest()
            // While PHP shuts down nothing runs any more: no reader to wait for, nor to wake
            if (null !== $this->reader && !self::$exiting) {
                // One of the application's reads waits on the socket: wake it, never close the
                // socket under it. A tunnel's input just ends, as on a drain, and the socket
                // stays readable for the linger below; any other read fails.
                if ($this->upgraded) {
                    phasync::cancel($this->reader);
                } else {
                    @\stream_socket_shutdown($this->socket, \STREAM_SHUT_RDWR);
                }
                do {
                    phasync::awaitFlag($this);
                } while (null !== $this->reader);
            }
            if ($this->linger && null === $this->ioError && !self::$exiting) {
                @\stream_socket_shutdown($this->socket, \STREAM_SHUT_WR);
                $deadline          = \microtime(true) + self::LINGER_TIMEOUT;
                $this->reclaimable = self::ANSWERED;
                try {
                    do {
                        phasync::readable($this->socket, $deadline - \microtime(true));
                        $data = @\fread($this->socket, self::READ_SIZE);
                    } while (false !== $data && !('' === $data && \feof($this->socket)) && \microtime(true) < $deadline);
                } catch (IOException|TimeoutException) {
                }
            }
            $this->reclaimable = 0;
            \fclose($this->socket);
            if (!self::$exiting) {
                phasync::raiseFlag($this); // a read waiting for an upgrade's status learns there will be none
            }
        }
    }

    /**
     * Stop keeping the connection alive, see NativeHttpServer::drain(). A request in flight is
     * answered with `Connection: close`. An idle kept-alive connection is closed now: a client
     * must expect that of a kept-alive connection, and retries. A new connection that sent
     * nothing yet gets DRAIN_NEW_WAIT to send its request first, since its client has no
     * reason to expect the close. Idle means nothing received: a request in the kernel's
     * buffer, not yet read, arrived before the drain, and is answered. A request body the
     * application reads after the response is left to it, as a request in flight is: the
     * worker's drain deadline bounds both.
     *
     * Returns whether the connection is upgraded (101).
     */
    public function drain(): bool
    {
        $this->draining = true;
        if ($this->upgraded) {
            // The application's protocol can only be ended by the application: its input ends,
            // as if the client had closed its side, after what swerve already took off the
            // socket (see readRaw(), awaitBody()), also within the upgrade request's own framed
            // body. It is expected to end its response then; one that doesn't
            // is dropped at the worker's drain deadline, which is logged. Not by shutting the
            // socket's reading side: the kernel would still take what the client sends, and
            // the closing linger (serve()) would find the end of the stream at once, closing
            // with that unread, which resets the connection and destroys what the application
            // wrote last, such as its goodbye.
            if (null !== $this->reader) {
                phasync::cancel($this->reader);
            }

            return true;
        }
        if (self::KEPT_ALIVE === $this->reclaimable && $this->nothingReceived()) {
            $this->reclaim(self::KEPT_ALIVE);
        } elseif (self::NEW === $this->reclaimable) {
            phasync::go(function () {
                phasync::sleep(self::DRAIN_NEW_WAIT);
                if (self::NEW === $this->reclaimable && $this->nothingReceived()) {
                    $this->reclaim(self::NEW);
                }
            });
        }

        return false;
    }

    private function nothingReceived(): bool
    {
        return \strlen($this->buffer) === $this->offset && '' === (string) @\stream_socket_recvfrom($this->socket, 1, \STREAM_PEEK);
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
        // Wakes this connection's own coroutine, which finds the end of the stream and closes,
        // or a read waiting for an upgrade's status (awaitStatus()), which gives the upgrade up
        \stream_socket_shutdown($this->socket, \STREAM_SHUT_RDWR);
        phasync::raiseFlag($this);

        return true;
    }

    /**
     * Up to $max bytes of the current request's body, at least 1: the caller never asks for
     * more than the body has left. From the buffer if it has any, otherwise from the socket,
     * reading up to $readable bytes (at most READ_SIZE) into the buffer: however little the
     * application asks for, one socket read takes what the body offers, but never more than
     * the caller says is the body's, so nothing beyond it is read ahead.
     *
     * After a read that got all it asked for, the kernel most likely holds more, so the next
     * read is tried at once instead of waiting on the event loop first (a syscall and a
     * coroutine switch that find nothing to wait for). Only BURST reads in a row, though: a
     * fast client would otherwise have the worker to itself until its body ends. After a short
     * read, a slow client's, the wait comes first, so no read finds nothing.
     *
     * @throws IOException|TimeoutException when the client went away or stalled
     */
    public function readBody(int $max, int $readable): string
    {
        $have = \strlen($this->buffer) - $this->offset;
        if (0 === $have) {
            $size = \min($readable, self::READ_SIZE);
            try {
                // No feof() when that read finds nothing: on a live socket it is one more
                // syscall, and at the end the read after the wait finds the end again
                $chunk = 0 === $this->fullReads % self::BURST ? '' : @\fread($this->socket, $size);
                while ('' === $chunk) {
                    $this->awaitBody();
                    $chunk = @\fread($this->socket, $size);
                    if ('' === $chunk && \feof($this->socket)) {
                        throw new IOException('Connection closed by the client');
                    }
                }
                if (false === $chunk) {
                    throw new IOException('Connection closed by the client');
                }
            } catch (IOException|TimeoutException $e) {
                throw $this->ioError = $e;
            }
            $this->fullReads      = \strlen($chunk) === $size ? $this->fullReads + 1 : 0;
            $this->bodyAllowance = \min(self::IO_TIMEOUT, $this->bodyAllowance + \strlen($chunk) / self::BODY_MIN_RATE);
            if (\strlen($chunk) <= $max) {
                return $chunk;
            }
            $this->buffer = $chunk; // the rest is for the next read
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
     * The bytes before the next CRLF, consuming the CRLF too. readLine(0) requires the next
     * two bytes to be exactly CRLF.
     *
     * @throws HttpError when the line is longer than $max
     * @throws IOException|TimeoutException when the client went away or stalled
     */
    public function readLine(int $max): string
    {
        $from = $this->offset;
        try {
            while (false === ($end = \strpos($this->buffer, "\r\n", $from))) {
                $have = \strlen($this->buffer) - $this->offset;
                if ($have > $max + 1) { // +1: a trailing CR may be half of the CRLF
                    throw new HttpError('Bad Request', 400);
                }
                $this->fill(null);
                $from = \max(0, $have - 1); // resume where the search stopped, never rescan
            }
        } catch (IOException|TimeoutException $e) {
            throw $this->ioError = $e;
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
     * Tell a client that sent `Expect: 100-continue` to send its body. Skipped when body bytes
     * are already here (that client didn't wait), and once the response head was sent: an
     * interim response after it would land inside the response body (RFC 9110 15.2).
     */
    public function sendContinue(): void
    {
        // Written from the reader's coroutine, which may not be this connection's: it could
        // overlap a response head being written, but a head is only written after handle()
        // returned, and a client that waits for 100 Continue sends nothing before it
        if (!$this->headSent && \strlen($this->buffer) === $this->offset) {
            $this->write("HTTP/1.1 100 Continue\r\n\r\n");
        }
    }

    /**
     * The current request body ended (read to its end, or failed), see settle().
     */
    public function bodyEnded(bool $failed): void
    {
        $this->bodyState = $failed ? self::BODY_FAILED : self::BODY_DONE;
        if ($this->settling) {
            phasync::raiseFlag($this);
        }
    }

    /**
     * A request body was released unfinished, see RequestBody::__destruct(). Only the current
     * request's body can be: the next request starts after its body ended or was released, and
     * any other outcome closes the connection. After the close, nothing keeps it: that would
     * make a cycle (body, connection, body) that phasync never collects.
     */
    public function release(RequestBody $body): void
    {
        if (self::BODY_OPEN === $this->bodyState && !$this->closed) {
            $this->released  = $body;
            $this->bodyState = self::BODY_RELEASED;
            if ($this->settling) {
                phasync::raiseFlag($this);
            }
        }
    }

    /**
     * A tunnel's next bytes (after a 101): the buffer first (bytes the client sent right behind
     * the upgrade head), then the socket, waiting without a timeout. '' at the end: the client
     * closed its side, or the server drains. Once draining, the socket is not read any more:
     * a client that keeps sending would otherwise keep the end from ever coming.
     *
     * @throws IOException when the connection was reset
     */
    public function readRaw(int $max): string
    {
        if (\strlen($this->buffer) !== $this->offset) {
            return $this->readBody($max, \PHP_INT_MAX); // from the buffer only
        }
        while (!$this->draining && !$this->closed) {
            $chunk = @\fread($this->socket, \min($max, self::READ_SIZE));
            if (false === $chunk) {
                throw $this->ioError = new IOException('Connection reset by the client');
            }
            if ('' !== $chunk) {
                return $chunk;
            }
            if (\feof($this->socket)) {
                break;
            }
            $this->reader = \Fiber::getCurrent();
            try {
                phasync::readable($this->socket, \PHP_FLOAT_MAX);
            } catch (CancelledException $e) {
                if (!$this->draining && !$this->closed) {
                    throw $e; // not drain()'s, nor serve()'s
                }
            } finally {
                $this->reader = null;
                if ($this->closed && !self::$exiting) {
                    phasync::raiseFlag($this); // serve() waits to close the socket
                }
            }
        }

        return '';
    }

    /**
     * The application gave its tunnel up (see RequestBody::close()): end the connection now,
     * also when a write waits on a client that stopped reading, which nothing else would end.
     */
    public function abort(): void
    {
        if (!$this->closed) {
            $this->ioError ??= new IOException('The application closed the connection');
            @\stream_socket_shutdown($this->socket, \STREAM_SHUT_RDWR);
        }
    }

    /**
     * Wait up to $wait seconds for the response status of an upgrade request whose body was read
     * past its framing (RequestBody::readOn()); respond() and the connection's end raise the flag.
     * Until a 101 the HTTP limits apply: the wait is one for more of the body, taken from the
     * client's allowance (BODY_TIMEOUT), and the connection may be closed to make room meanwhile.
     * Returns false when the status can't be waited for any more: the allowance is used up, or
     * the connection was closed to make room. Most likely handle() waits for this very read, as
     * middleware running the rest of the stack in a coroutine of its own does, which swerve can't
     * tell (curl sends `Upgrade: h2c` on plain requests).
     */
    public function awaitStatus(RequestBody $body, float $wait): bool
    {
        if ($this->bodyAllowance <= 0) {
            return false;
        }
        $start             = \microtime(true);
        $this->reclaimable = self::BODY;
        try {
            phasync::awaitFlag($this, \min($wait, $this->bodyAllowance));
        } catch (TimeoutException) {
        }
        if (!$body->switchable()) {
            return true; // the status is known: the connection's own coroutine has moved on
        }
        $this->bodyAllowance -= \microtime(true) - $start;
        if (self::BODY !== $this->reclaimable) {
            return false; // closed to make room
        }
        $this->reclaimable = 0;

        return true;
    }

    /**
     * The client closed its side, and neither swerve nor the kernel holds anything more from it.
     * For a read that waits for an upgrade's response status (RequestBody::readOn()): the body
     * has nothing more to give, whatever the status.
     */
    public function clientClosed(): bool
    {
        return \strlen($this->buffer) === $this->offset && '' === @\stream_socket_recvfrom($this->socket, 1, \STREAM_PEEK);
    }

    /**
     * Wait until more of a request body can be read: within the client's allowance (see
     * BODY_TIMEOUT), and when skipping an unread body, by its deadline. After a 101 (the
     * upgrade request's own body, sent after it) without either, and never closed to make room;
     * like readRaw(), drain() wakes that wait, also one that began before the 101 (the timeout
     * it began with then just starts it again), and once draining the socket is not waited on:
     * the tunnel's input ends (RequestBody::take()).
     *
     * @throws IOException|TimeoutException
     * @throws CancelledException after a 101, when the server drains
     */
    private function awaitBody(): void
    {
        $start   = \microtime(true);
        $timeout = $this->bodyAllowance;
        if ($this->upgraded) {
            if ($this->draining) {
                throw new CancelledException('The server drains');
            }
            $timeout = \PHP_FLOAT_MAX;
        } elseif (null === $this->discardUntil) {
            $this->reclaimable = self::BODY;
        } else {
            $this->reclaimable = self::ANSWERED;
            $timeout           = \min($timeout, $this->discardUntil - $start);
        }
        $this->reader = \Fiber::getCurrent();
        try {
            phasync::readable($this->socket, \max(0.0, $timeout));
        } catch (TimeoutException $e) {
            if (!$this->upgraded) {
                throw $e;
            }
            // The 101 went out during the wait: no HTTP timeout applies any more
        } finally {
            $this->reclaimable    = 0;
            $this->bodyAllowance -= \microtime(true) - $start;
            $this->reader         = null;
            if ($this->closed && !self::$exiting) {
                phasync::raiseFlag($this); // serve() waits to close the socket
            }
        }
    }

    /**
     * Read more from the socket into the buffer, waiting up to $timeout seconds (null: a wait
     * for more of a request body, see awaitBody()). Only called when the unconsumed data is
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
        while (true) {
            // Not @ around the wait: silencing is process-wide, and would last while other
            // coroutines run
            null === $timeout ? $this->awaitBody() : phasync::readable($this->socket, $timeout);
            $chunk = @\fread($this->socket, self::READ_SIZE);
            if (false === $chunk || ('' === $chunk && \feof($this->socket))) {
                throw new IOException('Connection closed by the client');
            }
            if ('' !== $chunk) {
                // Appended in place: a head arriving a byte at a time is not copied whole each time
                $this->buffer .= $chunk;
                if (null === $timeout) {
                    $this->bodyAllowance = \min(self::IO_TIMEOUT, $this->bodyAllowance + \strlen($chunk) / self::BODY_MIN_RATE);
                }

                return;
            }
        }
    }

    /**
     * Handle one request. Returns whether the connection stays open for another.
     *
     * @param bool $first the connection's first request: waiting for it is bounded by
     *                    HEAD_TIMEOUT, not by KEEP_ALIVE_TIMEOUT
     *
     * @throws HttpError when the request is refused
     */
    private function handleRequest(bool $first): bool
    {
        $this->headSent    = false;
        $this->bodyState   = self::BODY_OPEN;
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
                return false; // idle too long, or closed: nothing to answer
            }
        }
        $from = $this->offset;
        $deadline      = null;
        while (false === ($end = \strpos($this->buffer, "\r\n\r\n", $from))) {
            $have = \strlen($this->buffer) - $this->offset;
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
                return false;
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
        $this->request                = "$method $target";
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
        $headers    = [];
        $hosts      = 0;
        $host       = '';
        $hostName   = null;
        $cookie     = null;
        $length     = null;
        $te         = null;
        $connection = '';
        $expect     = null;
        $upgrade    = false;
        $contentType = '';
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
            $headers[$name][] = $value;
            switch (\strtolower($name)) {
                case 'host':
                    ++$hosts;
                    $host     = $value;
                    $hostName = $name;
                    break;
                case 'cookie':
                    $cookie = null === $cookie ? $value : "$cookie; $value";
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
                case 'content-type':
                    $contentType = $value;
                    break;
                case 'upgrade':
                    $upgrade = true;
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
        } elseif (null !== $length && $length > $this->maxBodySize) {
            throw new HttpError('Content Too Large', 413);
        }
        // close wins, in either version (RFC 9112 9.3); HTTP/1.0 persists only when asked to
        if ('' === $connection) {
            $keepAlive = '1.1' === $version;
        } else {
            $options   = 'close' === $connection || 'keep-alive' === $connection ? [$connection] : \array_map('trim', \explode(',', $connection));
            $keepAlive = !\in_array('close', $options, true) && ('1.1' === $version || \in_array('keep-alive', $options, true));
        }
        // An upgrade request (RFC 9110 7.8): only in HTTP/1.1, and only when Connection names it
        // (a lone "upgrade" takes the explode above too)
        $upgrade = $upgrade && '1.1' === $version && isset($options) && \in_array('upgrade', $options, true);

        $continue = false;
        if (null !== $expect && '1.1' === $version) {
            if (0 !== \strcasecmp($expect, '100-continue')) {
                throw new HttpError('Expectation Failed', 417);
            }
            $continue = null !== $te || $length > 0;
        }

        // An absolute-form target (RFC 9112 3.2.2): its authority is the host, and replaces the
        // Host header; the application gets its path as the target. The authority gets the Host
        // header's check. The scheme is always http: this connection is not TLS, whatever the
        // target says.
        if ('/' !== $target[0] && '*' !== $target) {
            $start = 0 === \strncasecmp($target, 'https://', 8) ? 8 : 7;
            $end   = \strcspn($target, '/?', $start);
            $host  = \substr($target, $start, $end);
            if ('' === $host || \strspn($host, self::HOST) !== $end) {
                throw new HttpError('Bad Request', 400);
            }
            $path   = \substr($target, $start + $end);
            $target = '/' === ($path[0] ?? '') ? $path : "/$path";
            if (null !== $hostName) {
                unset($headers[$hostName]);
            }
            $headers['Host'] = [$host];
        }

        $body = new RequestBody($this, null !== $te ? null : ($length ?? 0), $continue, $this->maxBodySize, $upgrade);
        $now     = \microtime(true);
        $request = new ServerRequest($method, $target, $body, $headers, [
            'REMOTE_ADDR'        => $this->remoteAddr,
            'REMOTE_PORT'        => $this->remotePort,
            'REQUEST_METHOD'     => $method,
            'REQUEST_URI'        => $target,
            'SERVER_PROTOCOL'    => $protocol,
            'REQUEST_TIME'       => (int) $now,
            'REQUEST_TIME_FLOAT' => $now,
        ], $version, $upgrade ? null : FormBody::for($method, $contentType, $body));
        if (null !== $cookie) {
            $request = $request->withCookieParams(ServerRequest::cookies($cookie));
        }

        // Each request runs in a coroutine and a phasync context of its own, which the coroutines it
        // starts share: request-scoped state can hang on phasync::getContext() (mini's does)
        $response = phasync::await(phasync::go(function () use ($request) {
            $this->fiber = \Fiber::getCurrent();

            return $this->handler->handle($request);
        }, context: new LoggingContext($this->logger)));
        if (null !== $this->ioError) {
            return false; // the application swallowed our socket failure: the client is gone
        }
        if (null !== $body->error) {
            throw $body->error; // the application swallowed a malformed body: answer it and close
        }

        $keepAlive = $this->writeResponse($response, $method, $version, $keepAlive, $body);
        if (!$body->eof()) {
            // The body is still the application's: it may be read later, from any coroutine.
            // Our references go; when nothing else holds it, it is released right here.
            $held = \WeakReference::create($body);
            unset($body, $request, $response);

            return $this->settle($keepAlive, $held);
        }

        // Closing: the client may have sent more (a pipelined request, the rest of a body)
        $this->linger = !$keepAlive;

        return $keepAlive;
    }

    /**
     * After the response, until the request body is read to its end, fails, or is released:
     * returns whether the next request may start. A released body's rest is skipped, within
     * DISCARD_LIMIT and DISCARD_TIMEOUT, or the connection closes. The client isn't waited for
     * here: the body's reader reads the socket, whenever the application gets to it.
     *
     * A body the application holds but hasn't read, or read only inside handle(), whose rest is
     * no larger than DISCARD_LIMIT, is read into the body (RequestBody::absorb()): the
     * application still reads
     * all of it, whenever it likes, and the connection goes on. A framework holding on to the
     * last request it answered (Slim's error handler does, also when the handler read the start
     * of the body and threw) would otherwise hold the connection. The client gets the time it
     * would have if the application read the body itself (BODY_TIMEOUT): swerve can't tell a
     * body that will be read in a moment, by a coroutine that hasn't started yet, from one just
     * held. The connection is then done with it: a drain doesn't wait for the application to
     * read it. A body read after the response is the application's, at its own pace, and the
     * drain waits for it. A larger body read, if at all, only inside handle() closes the
     * connection after KEEP_ALIVE_TIMEOUT, as an idle kept-alive one would. A tunnel whose response ended gives the application
     * LINGER_TIMEOUT in all to read the client's last bytes. Either close lingers (serve()).
     *
     * @param \WeakReference<RequestBody> $held the body, not kept alive by this
     */
    private function settle(bool $keepAlive, \WeakReference $held): bool
    {
        if (!$keepAlive) {
            // No next request: the client sees the response end now, not once the body is read
            @\stream_socket_shutdown($this->socket, \STREAM_SHUT_WR);
        }
        $until = $this->upgraded ? \microtime(true) + self::LINGER_TIMEOUT : null;
        // A body released already is still there (its destructor keeps it for the skip below)
        if (null === $until && self::BODY_OPEN === $this->bodyState && null === $this->reader && ($body = $held->get())?->absorbable(self::DISCARD_LIMIT)) {
            $body->absorb(self::DISCARD_LIMIT);
        }
        unset($body);
        $this->settling = true;
        try {
            while (self::BODY_OPEN === $this->bodyState) {
                try {
                    phasync::awaitFlag($this, null !== $until ? \max(0.0, $until - \microtime(true)) : self::KEEP_ALIVE_TIMEOUT);
                } catch (TimeoutException) {
                    if (null === $until) {
                        if (null !== $this->reader || $held->get()->readElsewhere) {
                            continue; // read by another coroutine: the application's, at its own pace
                        }
                        $this->logger->warning('{request}: the request body was held unread after the response; closing', ['request' => $this->request]);
                    }
                    // Closing, lingering: the held body can't be read any more, and what the
                    // client sent is read and dropped, or the kernel would reset the connection,
                    // destroying the response before the client read it (serve()). A read that
                    // waits in a tunnel reaches its input's end first.
                    $keepAlive = false;
                    break;
                }
            }
        } finally {
            $this->settling = false;
        }
        if (self::BODY_RELEASED === $this->bodyState) {
            $body           = $this->released;
            $this->released = null;
            if ($keepAlive) {
                $this->discardUntil = \microtime(true) + self::DISCARD_TIMEOUT;
                $keepAlive          = $body->discard(self::DISCARD_LIMIT);
                $this->discardUntil = null;
            }
        } elseif (self::BODY_FAILED === $this->bodyState) {
            $keepAlive = false;
        }
        $this->linger = !$keepAlive;

        return $keepAlive;
    }

    /**
     * Write the response. Returns whether the connection can be kept alive.
     *
     * The body is never read whole (unless $bufferResponses): its first piece is written
     * together with the head, the rest as it is read. The body's own size wins over an
     * application Content-Length header, and never more than that size is sent; a body that
     * ends early closes the connection, so the client sees it is truncated.
     *
     * @param RequestBody $request the request's body: when its unread rest won't be skipped after
     *                             the response, the response says the connection closes. That is
     *                             known once the response body's first piece is read (which may
     *                             be this request body, echoed): a client that still waits for
     *                             `100 Continue` may never send it, and too large a rest, or one
     *                             found malformed, isn't read. A body the application started
     *                             reading counts as being read on (it may be, from another
     *                             coroutine, after the response); if it is released instead,
     *                             the connection closes after the response all the same.
     */
    private function writeResponse(ResponseInterface $response, string $method, string $version, bool $keepAlive, RequestBody $request): bool
    {
        $status = $response->getStatusCode();
        $tunnel = false;
        if ($status < 200) {
            if (101 !== $status || !$request->switchable()) {
                throw new \UnexpectedValueException(101 === $status
                    ? '101 answers only an HTTP/1.1 upgrade request whose body handle() did not read past its framing'
                    : "A final response can't have status $status");
            }
            $tunnel = true;
        }
        $head       = "HTTP/1.1 $status " . $response->getReasonPhrase() . "\r\n";
        $lines      = 1;
        $appLength  = null;
        $addDate    = true;
        $connection = ''; // the application's Connection options, each after ", "
        foreach ($response->getHeaders() as $name => $values) {
            switch (\strtolower($name)) {
                case 'content-length':
                    $v = (string) $values[0];
                    if (\ctype_digit($v) && \strlen($v) <= 18) {
                        $appLength = (int) $v;
                    }
                    continue 2;
                case 'connection':
                    if ($tunnel) {
                        break; // as the application sets it: the connection is its protocol's now
                    }
                    // Its options are passed on (an Upgrade needs "upgrade" among them, RFC 9110
                    // 7.8), but close and keep-alive are decided below. Only tokens: the line is
                    // added after the head's check for CR, LF and NUL.
                    foreach ($values as $value) {
                        foreach (\explode(',', (string) $value) as $option) {
                            $option = \trim($option, " \t");
                            if (0 === \strcasecmp($option, 'close')) {
                                $keepAlive = false;
                            } elseif ('' !== $option && 0 !== \strcasecmp($option, 'keep-alive')) {
                                if (\strspn($option, self::TOKEN) !== \strlen($option)) {
                                    throw new \UnexpectedValueException("Response Connection option '$option' is not a token");
                                }
                                $connection .= ", $option";
                            }
                        }
                    }
                    continue 2;
                case 'upgrade':
                    break; // on a 101 the protocol switched to; on another status one offered (RFC 9110 7.8), which a 426 must name
                case 'transfer-encoding':
                case 'keep-alive':
                    continue 2; // this connection's framing is decided here (a 1xx has none)
                case 'date':
                    $addDate = false;
            }
            // Only a token: "Content-Length " or " Folded" would be a second framing header or
            // an obs-fold line to a lenient proxy
            if ('' === $name || \strspn($name, self::TOKEN) !== \strlen($name)) {
                throw new \UnexpectedValueException("Response header name '$name' is not a token");
            }
            foreach ($values as $value) {
                $head .= "$name: $value\r\n";
                ++$lines;
            }
        }
        if ($addDate) {
            if (self::$dateTime !== ($time = \time())) {
                self::$dateTime = $time;
                self::$date     = \gmdate('D, d M Y H:i:s', $time) . ' GMT';
            }
            $head .= 'Date: ' . self::$date . "\r\n";
            ++$lines;
        }
        // Each line added one CRLF; any other CR, LF or NUL came from the application and could
        // inject headers or split the response. Three C-level scans over the head, whatever
        // PSR-7 implementation (or reason phrase, which PSR-7 implementations don't all check) produced it.
        if (\substr_count($head, "\n") !== $lines || \substr_count($head, "\r") !== $lines || \str_contains($head, "\0")) {
            throw new \UnexpectedValueException('CR, LF or NUL in a response header or reason phrase');
        }
        if ($request->upgrade) {
            // Before any read of the response body, which may be this request body
            $request->respond($status);
        }
        if ($tunnel) {
            return $this->tunnel($head, $response->getBody(), $request);
        }

        $body    = $response->getBody();
        $chunked = false;
        $first   = '';
        $echo    = false; // the response body is the request's own: streaming it reads the request's rest
        if ('HEAD' === $method || 204 === $status || 205 === $status || 304 === $status) {
            // No body; the body stream is never read. Slim empties a HEAD response's body, so a
            // size of 0 there says nothing. A 205 has none either (RFC 9110 15.3.6), but unlike
            // 204 and 304 its status alone doesn't say so, so it says Content-Length: 0.
            $n = 204 === $status ? null : (205 === $status ? 0 : ($appLength ?? ('HEAD' === $method ? ($body->getSize() ?: null) : null)));
            if (null !== $n) {
                $head .= "Content-Length: $n\r\n";
            }
            $size = 0;
        } else {
            $echo     = $body === $request;
            $seekable = $body->isSeekable();
            if ($seekable) {
                $body->rewind();
            }
            // A size of 0 says nothing: PHP streams report it for pipes, sockets and /proc files.
            // The size is the whole stream's, and a non-seekable one may have been read partly.
            $size = $body->getSize();
            if ($size && !$seekable) {
                $size -= $body->tell();
            }
            $size = $size ?: $appLength;
            if ($this->bufferResponses) {
                $cap = \min($size ?? self::RESPONSE_BUFFER_LIMIT, self::RESPONSE_BUFFER_LIMIT);
                while (\strlen($first) < $cap && '' !== ($piece = $this->readPiece($body, \min(self::READ_SIZE, $cap - \strlen($first)), $seekable))) {
                    $first .= $piece;
                }
            } elseif (0 !== $size) {
                $first = $body->read($n = null === $size ? self::READ_SIZE : \min($size, self::READ_SIZE));
                if (\strlen($first) !== $n) {
                    $first = $this->readPiece($body, $n, $seekable, $first);
                }
            }
            if (null === $size && $body->eof()) {
                $size = \strlen($first); // the whole body came in one piece: no chunked framing needed
            }
            if (null !== $size) {
                $head .= "Content-Length: $size\r\n";
            } elseif ('1.1' === $version) {
                $head   .= "Transfer-Encoding: chunked\r\n";
                $chunked = true;
            } else {
                $keepAlive = false; // HTTP/1.0 and unknown size: the body ends at the close
            }
        }
        if (!$request->eof() && ($request->continuePending() || (!$echo && 0 === $request->tell() && !$request->discardable(self::DISCARD_LIMIT)))) {
            $keepAlive = false;
        }
        if ($this->draining) {
            $keepAlive = false;
        }
        $connection .= $keepAlive ? ('1.0' === $version ? ', keep-alive' : '') : ', close';
        if ('' !== $connection) {
            $head .= 'Connection: ' . \substr($connection, 2) . "\r\n";
        }

        $this->headSent = true;
        if ($chunked) {
            // Each piece is framed in one interpolated string: copied once, not once per
            // concatenation
            $end = $body->eof() ? "0\r\n\r\n" : '';
            $hex = \dechex(\strlen($first));
            $this->write('' === $first ? "$head\r\n$end" : "$head\r\n$hex\r\n$first\r\n$end");
            while ('' === $end) {
                $chunk = $this->readPiece($body, self::READ_SIZE, $seekable);
                $end   = '' === $chunk || $body->eof() ? "0\r\n\r\n" : '';
                $hex   = \dechex(\strlen($chunk));
                $this->write('' === $chunk ? $end : "$hex\r\n$chunk\r\n$end");
            }

            return $keepAlive;
        }
        $this->write($head . "\r\n" . $first);
        if (null === $size) {
            while ('' !== ($chunk = $this->readPiece($body, self::READ_SIZE, $seekable))) {
                $this->write($chunk);
            }

            return false;
        }
        for ($left = $size - \strlen($first); $left > 0; $left -= \strlen($chunk)) {
            $chunk = $this->readPiece($body, \min($left, self::READ_SIZE), $seekable);
            if ('' === $chunk) {
                $this->logger->warning('Response body ended {left} bytes before its declared size', ['left' => $left]);

                return false;
            }
            $this->write($chunk);
        }

        return $keepAlive;
    }

    /**
     * Switch protocols (101): the head goes out at once, alone, since the client sends nothing
     * more until it has it (waiting for the body's first piece would deadlock). A client still
     * waiting for `100 Continue` gets it first (RFC 9110 7.8). Then the body goes out unframed
     * as it is read, until it ends; the connection then closes (settle(), serve()). No HTTP
     * limit or timeout applies from here on, and $bufferResponses never does: the connection is
     * the application's protocol's, and it alone knows how long a silence may last.
     */
    private function tunnel(string $head, StreamInterface $body, RequestBody $request): bool
    {
        $this->upgraded    = true;
        $this->headSent    = true;
        $this->reclaimable = 0; // a read of the body's framed part may wait, since before the 101
        $this->write(($request->continuePending() ? "HTTP/1.1 100 Continue\r\n\r\n" : '') . "$head\r\n");
        $seekable = $body->isSeekable();
        if ($seekable) {
            $body->rewind();
        }
        while ('' !== ($piece = $this->readPiece($body, self::READ_SIZE, $seekable))) {
            $this->write($piece);
        }

        return false;
    }

    /**
     * The response body's next piece: at most $max bytes, and '' only at its end.
     *
     * A seekable body (in memory or a file, where a read never waits for a slow source) is read
     * until the piece is full, since PHP reads a file 8 KiB at a time and each piece is one
     * write. Any other body's piece is what one read gives, so it goes out as it is produced.
     *
     * PSR-7 lets read() return '' before the end, as a stream over a non-blocking resource does
     * while it has nothing. It exposes nothing to wait on, so this sleeps, a little longer each
     * time: reading again at once would spin, and keep every other coroutine of the worker
     * (maybe the one producing the data) from running. Since nothing is written meanwhile, a
     * client that left would never be noticed, so the socket is checked each time: a client
     * that closed (or half-closed: it sends nothing more either) ends the response. In a tunnel
     * a client's half-close is the request body's EOF, for the application to act on.
     *
     * @param ?string $piece what a first read($max) already returned
     *
     * @throws \UnexpectedValueException when read() returned more than asked for, which would
     *                                    break the response's framing
     * @throws IOException               when the client left while waiting for the body
     */
    private function readPiece(StreamInterface $body, int $max, bool $seekable, ?string $piece = null): string
    {
        $piece ??= $body->read($max);
        for ($wait = 0.001; '' === $piece && !$body->eof(); $wait = \min($wait * 2, 0.05)) {
            phasync::sleep($wait);
            if (!$this->upgraded && \feof($this->socket)) {
                throw $this->ioError = new IOException('Connection closed by the client');
            }
            $piece = $body->read($max);
        }
        if ($seekable) {
            while (\strlen($piece) < $max && '' !== ($more = $body->read($max - \strlen($piece)))) {
                $piece .= $more;
            }
        }
        if (\strlen($piece) > $max) {
            throw new \UnexpectedValueException("The response body's read($max) returned " . \strlen($piece) . ' bytes');
        }

        return $piece;
    }

    /**
     * Answer a refused request: one best-effort write that never waits, then a lingering close.
     */
    private function writeError(int $status, string $reason): void
    {
        @\fwrite($this->socket, "HTTP/1.1 $status $reason\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        $this->linger = true;
    }

    /**
     * @throws IOException|TimeoutException when the client went away or stopped reading
     */
    private function write(string $data): void
    {
        try {
            while (true) {
                $written = @\fwrite($this->socket, $data);
                if (false === $written) {
                    throw new IOException('Connection closed by the client');
                }
                if ($written === \strlen($data)) {
                    return;
                }
                $data = \substr($data, $written);
                phasync::writable($this->socket, $this->upgraded ? \PHP_FLOAT_MAX : self::IO_TIMEOUT);
            }
        } catch (IOException|TimeoutException $e) {
            throw $this->ioError = $e;
        }
    }
}
