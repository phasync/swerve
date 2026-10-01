<?php

namespace Swerve\Http;

use phasync;
use phasync\CancelledException;
use phasync\IOException;
use phasync\TimeoutException;
use Psr\Http\Message\StreamInterface;

/**
 * The body of a request in HTTP mode, read straight from the connection as the application
 * reads it: a Content-Length body, or a chunked one decoded on the fly. Nothing is read ahead:
 * bytes already in the connection's buffer are used first, then the socket is read for at
 * most what the body has left (up to 64 KiB, however little the application asks for).
 *
 * What the application may count on:
 *
 * 1. Reading after the response. The body stays connected to the socket as long as the
 *    application holds it: it may be read after handle() returned, from any coroutine, also
 *    while the response is being written (full duplex). read() returns '' only at the end. The
 *    next request on the connection waits until the body is read to its end, or released; so
 *    does a drain (shutdown, reload, recycle), up to its deadline.
 * 2. Releasing. A body released unfinished (its last reference dropped) has its rest skipped,
 *    up to 64 KiB within 5 s, or else the connection closes. A reference held by a framework's
 *    container, by a cycle (phasync does not collect cycles while it runs) or by an exception's
 *    trace holds the body too.
 * 3. Holding without reading. A body still held after the response, not read so far (or only
 *    inside handle()), whose rest is no larger than 64 KiB, is read into memory for the
 *    application, the client getting the same time as if the application read it: the
 *    application reads it whenever it likes, and the connection goes on without waiting for it
 *    (nor does a drain). Frameworks keep requests they answered (Slim's error handler keeps the
 *    last one, also when the handler read the start of the body and threw). A larger one that
 *    is never read after that closes the connection after 30 s, which is logged; reads after
 *    that throw. A body another coroutine reads is never taken for one held unread, however
 *    long between reads. A
 *    `Connection: close` response still lets the body be read: the client sees the response end
 *    at once. With `Expect: 100-continue`, read before returning the response, or the
 *    connection closes: `100 Continue` can't follow the response head.
 * 4. Upgrade requests: HTTP/1.1 with an Upgrade header and "upgrade" among the Connection
 *    options. Where the body ends depends on the response, so getSize() is null. Past its
 *    framed part (usually at once: there is none), a read from another coroutine waits for the
 *    response status. After a 101 the body is the raw rest of what the client sends, bytes
 *    sent right behind the head included; it reaches EOF when the client closes its side, the
 *    server drains, or the connection closes. After any other status it ends at its framing,
 *    and the connection is kept alive; the response's Upgrade header and Connection options are
 *    sent as given (a 426 must name the protocol it requires). Reading past the framing inside
 *    handle() itself declines the upgrade (the response can't be waited for there): read()
 *    returns '', and a 101 answer then becomes a 500. A client that closes its side while a
 *    read waits for the status ends the body there (after what it sent before), whatever the
 *    status: a 101 still goes out. Until the status is known the HTTP limits apply: the wait
 *    counts against the client's time for the body (10 s, more as it sends), and the connection
 *    may be closed to make room. Code in handle() that waits for a coroutine reading the body
 *    past its framing (curl sends `Upgrade: h2c` on plain requests) is stuck until that time
 *    is up: the upgrade is then declined, as inside handle().
 * 5. Answering 101 (see HttpConnection::tunnel()): set Upgrade and Connection yourself,
 *    they are sent as given. The head goes out as soon as handle() returns; the response body
 *    goes out unframed as it is read, and its end closes the connection (the request body then
 *    gets the client's last bytes for up to 2 s). No HTTP timeout or size limit applies, also
 *    not to the request's own framed body sent after the 101: use the application's protocol's
 *    own, such as pings. To give the connection up (a client that stopped answering), close()
 *    the request body: that ends the connection at once, also while a write of the response
 *    waits on a client that stopped reading. The response body's read() should wait for data,
 *    as phasync's `new UnbufferedStream(65536, PHP_FLOAT_MAX)` does (its default 60 s deadlock
 *    timeout turns an idle connection into an application error); one returning '' is polled,
 *    with up to 50 ms between polls. When the request body reaches EOF, end the response with
 *    the protocol's goodbye.
 * 6. Shutdown, reload and recycle. An upgraded connection's request body reaches EOF at once,
 *    after what swerve already took off the socket, however much more the client sends: end the
 *    response then. Its goodbye reaches the client: the connection closes lingering, reading
 *    what the client still sends for up to 2 s. Past the drain deadline the worker exits and
 *    drops the connection, which is logged. Upgraded connections are never closed to make room
 *    at the connection limit, which without the phasync extension is 960 per worker, whatever
 *    `ulimit -n` says: add workers, or install the extension, for many of them.
 *
 * A client that goes away mid-body makes read() throw a RuntimeException, as PSR-7 says.
 *
 * With `Expect: 100-continue`, the first read() sends `100 Continue`, so a client waiting
 * for it only sends the body when the application actually wants it.
 *
 * A malformed chunked body throws HttpError (400) from read(), as does a chunk that makes
 * the body larger than $maxSize (413), and every later read throws it again; the connection
 * answers with its status (or, after the response, just closes), even when the application
 * caught it.
 */
final class RequestBody implements StreamInterface
{
    /** Content-Length bytes left, or bytes left of the current chunk. */
    private int $remaining;

    /** A chunk's data is used up; its CRLF comes next. */
    private bool $chunkEnd = false;

    private bool $done;
    private int $position = 0;

    /** The framed part is used up: the Content-Length is consumed, or the last chunk and its trailer read. */
    private bool $framingEnded;

    /**
     * Whether the body goes on raw past its framing: null while an upgrade's response status is
     * unknown, true after a 101, false otherwise (not an upgrade, not switched, or declined).
     */
    private ?bool $raw;

    /** The body was malformed; sticky, so the connection sees it even if the app caught it. */
    public ?HttpError $error = null;

    /** The body's rest, read by the connection for the application, see absorb(). */
    private string $kept = '';

    /** absorb() reads the socket: the application's reads wait for it. */
    private bool $absorbing = false;

    /**
     * A coroutine other than handle()'s read the body: it reads on at its own pace, see
     * absorbable() and HttpConnection::settle().
     */
    public bool $readElsewhere = false;

    /**
     * @param ?int $length  the Content-Length, or null for a chunked body
     * @param bool $continue send `100 Continue` on the first read
     * @param int  $maxSize  the largest chunked body; a Content-Length is checked before
     * @param bool $upgrade  an upgrade request, whose body's end depends on the response
     */
    public function __construct(
        private readonly HttpConnection $connection,
        private readonly ?int $length,
        private bool $continue,
        private readonly int $maxSize,
        public readonly bool $upgrade = false,
    ) {
        $this->remaining    = $length ?? 0;
        $this->raw          = $upgrade ? null : false;
        $this->framingEnded = 0 === $length;
        $this->done         = $this->framingEnded && !$upgrade;
    }

    /**
     * Returns '' only when the body has ended.
     */
    public function read($length): string
    {
        $this->readElsewhere = $this->readElsewhere || \Fiber::getCurrent() !== $this->connection->fiber;
        while ($this->absorbing) {
            phasync::awaitFlag($this);
        }
        if (null !== $this->error) {
            throw $this->error;
        }
        if ('' !== $this->kept && $length > 0) {
            $bytes      = \substr($this->kept, 0, $length);
            $this->kept = \substr($this->kept, \strlen($bytes));

            return $bytes;
        }

        return $this->take($length);
    }

    /**
     * The body's next bytes from the connection.
     */
    private function take(int $length): string
    {
        if ($this->done || $length <= 0) {
            return '';
        }
        try {
            if ($this->connection->closed) {
                if (true === $this->raw) {
                    $this->end(); // a tunnel's input simply ended

                    return '';
                }
                throw new IOException('The connection is closed');
            }
            if ($this->continue) {
                $this->continue = false;
                $this->connection->sendContinue();
            }
            if (!$this->framingEnded) {
                if (null === $this->length && 0 === $this->remaining) {
                    $this->nextChunk(); // at the last chunk, the framing ends
                }
                if (!$this->framingEnded) {
                    // What follows a chunk's data is still this body (its CRLF, the next chunk-size
                    // line), so a socket read may take more than the chunk has left
                    $bytes            = $this->connection->readBody(\min($length, $this->remaining), null === $this->length ? \PHP_INT_MAX : $this->remaining);
                    $n                = \strlen($bytes);
                    $this->remaining -= $n;
                    $this->position  += $n;
                    if (0 === $this->remaining) {
                        if (null === $this->length) {
                            $this->chunkEnd = true;
                        } else {
                            $this->endFraming();
                        }
                    }

                    return $bytes;
                }
                if ($this->done) {
                    return '';
                }
            }

            return $this->readOn($length);
        } catch (HttpError $e) {
            $this->connection->bodyEnded(true);
            throw $this->error = $e;
        } catch (IOException $e) {
            $this->connection->bodyEnded(true);
            // Not a RuntimeException, the only exception PSR-7 lets read() throw (a timeout is one)
            throw new \RuntimeException($e->getMessage(), 0, $e);
        } catch (TimeoutException $e) {
            $this->connection->bodyEnded(true);
            throw $e;
        } catch (CancelledException $e) {
            if (true !== $this->raw || (!$this->connection->draining && !$this->connection->closed)) {
                throw $e; // not the drain's, nor the connection's end
            }
            // A tunnel's input ends on a drain or at the connection's end, also within the
            // upgrade request's own framed body
            $this->end();

            return '';
        }
    }

    /**
     * Whether absorb() may take the body's rest: one read, if at all, only inside handle(),
     * which has returned (a framework's error handler may keep a request whose start was read);
     * no more than $max bytes, as far as is known without reading; not going on as a tunnel. A
     * body another coroutine reads is its own to read to the end, at its own pace, and the
     * drain waits for it.
     */
    public function absorbable(int $max): bool
    {
        return !$this->readElsewhere && false === $this->raw && !$this->done && $this->discardable($max);
    }

    /**
     * Read the body's rest into the body, for the application to read whenever it likes: after
     * the response, while the application holds the body but isn't reading it, so the connection
     * can go on (see HttpConnection::settle()). The client gets the time it would have if
     * the application read the body itself. A chunked body is taken only up to about
     * $max bytes; its rest stays on the socket, for the application. A failure is left for the
     * application's reads to find: a malformed body's error, or what arrived and then a
     * RuntimeException, since the connection closes.
     */
    public function absorb(int $max): void
    {
        $this->absorbing = true;
        try {
            while (!$this->done && \strlen($this->kept) <= $max) {
                $this->kept .= $this->take(65536);
            }
        } catch (\RuntimeException) {
        } finally {
            $this->absorbing = false;
            if (!HttpConnection::$exiting) {
                phasync::raiseFlag($this); // a read waiting for it
            }
        }
    }

    /**
     * Whether a 101 may answer the request: an upgrade whose body wasn't declined inside handle().
     */
    public function switchable(): bool
    {
        return null === $this->raw;
    }

    /**
     * The response status is known (upgrade requests only, see HttpConnection::writeResponse()):
     * a read waiting for it goes on, as the raw rest of the connection (101) or to the body's end.
     */
    public function respond(int $status): void
    {
        if (null !== $this->raw) {
            return; // declined
        }
        $this->raw = 101 === $status;
        if (!$this->raw && $this->framingEnded && !$this->done) {
            $this->end();
        }
        phasync::raiseFlag($this->connection);
    }

    /**
     * The client is waiting for `100 Continue`, and the application never read the body, so
     * it may never arrive.
     */
    public function continuePending(): bool
    {
        return $this->continue;
    }

    /**
     * Whether discard($max) may keep the connection, as far as is known without reading.
     */
    public function discardable(int $max): bool
    {
        return $this->done || (null === $this->error && !$this->continue && (null === $this->length || $this->remaining <= $max));
    }

    /**
     * Read and throw away what the application left unread, so the next request starts in
     * the right place. Returns false, reading nothing more, when that would cost more than
     * $max bytes, the body is malformed, or the client still waits for `100 Continue`: the
     * connection must then close.
     */
    public function discard(int $max): bool
    {
        if ($this->done) {
            return true;
        }
        if (!$this->discardable($max)) {
            return false;
        }
        try {
            while (!$this->done) {
                if (($max -= \strlen($this->read(65536))) < 0) {
                    return false;
                }
            }
        } catch (HttpError) {
            return false;
        }

        return true;
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(65536);
        }

        return $contents;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function eof(): bool
    {
        return $this->done && '' === $this->kept;
    }

    public function getSize(): ?int
    {
        return $this->upgrade ? null : $this->length;
    }

    public function tell(): int
    {
        return $this->position - \strlen($this->kept);
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    /**
     * Only to where the body already is: nothing to do. Slim's MethodOverrideMiddleware
     * rewinds every POST body at its end, which an empty body is before it is read.
     */
    public function seek($offset, $whence = \SEEK_SET): void
    {
        $to = match ($whence) {
            \SEEK_SET => $offset,
            \SEEK_CUR => $this->position + $offset,
            default   => null,
        };
        if ($to !== $this->position) {
            throw new \RuntimeException('The request body is not seekable');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function write($string): int
    {
        throw new \RuntimeException('The request body is not writable');
    }

    /**
     * After a 101, ends the connection at once: the way to give a tunnel up, also one whose
     * client stopped reading, where a write of the response waits (HttpConnection::abort()).
     * Otherwise does nothing: releasing the body (see __destruct()) is what hands its rest back
     * to the connection, which skips it or closes.
     */
    public function close(): void
    {
        if (true === $this->raw) {
            $this->connection->abort();
        }
    }

    public function detach()
    {
        return null;
    }

    public function getMetadata($key = null)
    {
        return null === $key ? [] : null;
    }

    /**
     * Read the next chunk's size line (after the previous chunk's CRLF). At the last chunk,
     * read and discard the trailer fields and mark the body done.
     *
     * @throws HttpError on any framing violation
     */
    private function nextChunk(): void
    {
        if ($this->chunkEnd) {
            $this->connection->readLine(0); // exactly CRLF after the chunk's data
            $this->chunkEnd = false;
        }
        $line   = $this->connection->readLine(HttpConnection::MAX_CHUNK_LINE);
        $digits = \strspn($line, '0123456789abcdefABCDEF');
        // At most 15 hex digits, so the size always fits an int; extensions are accepted and ignored
        if (0 === $digits || $digits > 15 || ($digits !== \strlen($line)
            && 1 !== \preg_match('/\A[ \t]*;[\t\x20-\x7E\x80-\xFF]*\z/', \substr($line, $digits)))) {
            throw new HttpError('Bad Request', 400);
        }
        $this->remaining = \hexdec(\substr($line, 0, $digits));
        // After a 101 no HTTP limit applies
        if ($this->remaining > $this->maxSize - $this->position && true !== $this->raw) {
            throw new HttpError('Content Too Large', 413);
        }
        if (0 === $this->remaining) {
            $total = 0;
            $count = 0;
            while ('' !== ($trailer = $this->connection->readLine(HttpConnection::MAX_CHUNK_LINE))) {
                $total += \strlen($trailer) + 2;
                if (++$count > HttpConnection::MAX_HEADERS || $total > HttpConnection::MAX_HEAD
                    || 1 !== \preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+:[\t\x20-\x7E\x80-\xFF]*\z/', $trailer)) {
                    throw new HttpError('Bad Request', 400);
                }
            }
            $this->endFraming();
        }
    }

    /**
     * The framed part is used up: the body ends here, unless an upgrade's status is still
     * unknown, or is 101.
     */
    private function endFraming(): void
    {
        $this->framingEnded = true;
        if (false === $this->raw) {
            $this->end();
        }
    }

    private function end(): void
    {
        $this->done = true;
        $this->connection->bodyEnded(false);
    }

    /**
     * An upgrade request's body past its framing: it waits for the response status, then is the
     * raw rest of the connection (101), or over. The upgrade is declined, the body ending here
     * and a 101 answer becoming a 500, when the status can't come: in handle()'s own coroutine,
     * so that middleware reading every body (curl sends `Upgrade: h2c` on plain requests) never
     * deadlocks; and once the wait has used up the client's allowance for the body, as a
     * handler that waits for a coroutine reading here would, which swerve can't tell (see
     * HttpConnection::awaitStatus()). A server may always ignore Upgrade (RFC 9110 7.8).
     * When the client closed its side, and everything it sent before was read, the body ends
     * here whatever the status: nothing can follow, and a 101 still goes out, to a client that
     * only listens, or is gone. The client is looked at now and then (up to a second apart),
     * since the wait is for a flag, not the socket.
     */
    private function readOn(int $length): string
    {
        for ($wait = 0.05; null === $this->raw; $wait = \min($wait * 2, 1.0)) {
            if (\Fiber::getCurrent() !== $this->connection->fiber) {
                if ($this->connection->clientClosed()) {
                    $this->end(); // not declined: respond() finds the body done

                    return '';
                }
                $waiting = $this->connection->awaitStatus($this, $wait);
                if ($this->connection->closed) {
                    throw new IOException('The connection is closed'); // no response will come
                }
                if ($waiting || null !== $this->raw) {
                    continue;
                }
            }
            $this->raw = false;
            $this->end();

            return '';
        }
        if ($this->done) {
            return ''; // not switched: respond() ended it
        }
        $bytes = $this->connection->readRaw($length);
        if ('' === $bytes) {
            $this->end();

            return '';
        }
        $this->position += \strlen($bytes);

        return $bytes;
    }

    /**
     * Released unfinished: the application dropped its last reference before the body's end.
     * The connection gets it, and skips its rest or closes (HttpConnection::settle()).
     * Storing $this keeps the object alive, and PHP runs a destructor only once, so the skip
     * reads through it. Never suspends: this may run in any coroutine, or in the collector.
     */
    public function __destruct()
    {
        if (!$this->done) {
            $this->connection->release($this);
        }
    }
}
