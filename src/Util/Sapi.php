<?php

namespace Swerve\Util;

use phasync\IOException;
use phasync\TimeoutException;
use Swerve\ClientRequest;

/**
 * The `$sapi` object `sapi()` calls for one request (ext-phasync's, or without it phasync/hooked's,
 * which takes the same object): wraps Swerve's
 * {@see ClientRequest}, as a SAPI module wraps the real connection. Every request in a worker runs
 * inside it, so `echo`, `header()`, `php://input` and the session are the request's own, whatever
 * its handler.
 *
 * The request body and the response body go through a socket pair: the request's end is what
 * output() and input() return, read and written by PHP's own SAPI layer; this end is pumped
 * from and to the client by coroutines of its own. Status and headers stay structured calls.
 * No method the extension calls waits: the client I/O is the pumps'. The output pump starts
 * only when needed: on flush() or a 1xx head, or once the request waits for anything (it may
 * be waiting for the pump). A request that runs through without waiting (most small pages)
 * has its response sent from the pair when it ends, with no coroutine of its own.
 *
 * @internal
 */
final class Sapi
{
    /** Idle response pairs, drained, for later requests: [request end, server end]. */
    private static array $pairs = [];

    /** At most this many idle pairs are kept. */
    private const PAIRS_KEPT = 64;

    /** Requests in flight (their Sapi constructed, not ended yet). */
    private static int $live = 0;

    /** The request whose script is running (or just bailed): the one a shutdown function finishes. */
    public static ?self $current = null;

    /** Whether the pump has read the pair empty: every byte of it delivered to the client. */
    private bool $pairEmpty = false;

    /** The client this Sapi answers, so a shutdown function can check it is finishing *this* request. */
    public function client(): ClientRequest
    {
        return $this->client;
    }

    /**
     * Requests in flight in this worker: constructed, not ended. Their bytes may still be on their
     * way to a client, which is what a run has to wait for before it cancels anything -- see the
     * end of the application's accept() in Worker::serve().
     */
    public static function inFlight(): int
    {
        return self::$live;
    }

    /** @var resource the request's end of the response pair */
    private $request;

    /** @var resource this end of it (non-blocking): the response arrives here */
    private $server;

    /** @var resource|null the request body's pair, request's end and this end (when it has one) */
    private $bodyRequest;
    private $bodyServer;

    /** The client went away mid-response: the pair may hold the rest, it is not reused. */
    private bool $broken = false;

    /** The output pump, once started. */
    private ?\Fiber $pump = null;

    /** The request has ended: the pump is no longer started for it. */
    private bool $ended = false;

    /** Whether the output pump waits for the response (and may be woken). */
    private bool $idle = false;

    /** A 1xx head, or a flush of a head with no body yet, for the output pump to send. */
    private ?\Closure $pending = null;

    /** Raised each time the output pump has sent what was pending. */
    private object $sent;

    /**
     * @param bool $body whether the request body is read through PHP (`php://input`,
     *                   `request_parse_body()`): a script's; a handler given the ClientRequest
     *                   reads it there
     */
    public function __construct(private readonly ClientRequest $client, bool $body)
    {
        while (![$this->request, $this->server] = \array_pop(self::$pairs)) {
            $pair = @\stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
            if (false !== $pair) {
                [$this->request, $this->server] = $pair;
                // The pumps' client timeouts govern; the request's end only waits for the pump.
                \stream_set_timeout($this->request, 86400 * 365);
                \stream_set_blocking($this->server, false);
                break;
            }
            /* Out of file descriptors (the pool is empty and a fresh pair fails): short of
             * descriptors, not of connections -- say so, and wait for a request to end and
             * hand its pair back. The alternative -- this request failing -- is how a flood
             * past the open-file limit would answer 500s for no application reason. */
            \Swerve\Swerve::logShort();
            \phasync::sleep(0.05);
        }
        ++self::$live;
        $this->sent = new \stdClass();
        // After this tick: the request is still running, so it waits for something. The pump is
        // a coroutine of the request's frame and scope, as phasync::go() here would make it.
        $frame = \phasync::frame();
        $scope = \Scope::getCurrent();
        \phasync::getLoop()->defer(function () use ($frame, $scope): void {
            if (!$this->ended && null === $this->pump) {
                $this->pump = \phasync::getLoop()->create($this->pumpOutput(...), [], $frame, $scope);
            }
        });
        if ($body && !$client->eof()) {
            [$this->bodyRequest, $this->bodyServer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
            \stream_set_timeout($this->bodyRequest, 86400 * 365);
            \stream_set_timeout($this->bodyServer, 86400 * 365);
            \phasync::go($this->pumpInput(...));
        }
    }

    /**
     * Runs $code as the request, then ends this end of the response once the pump has sent all
     * of it.
     */
    public function run(\Closure $code): void
    {
        self::$current = $this; // whose output a shutdown function is finishing: see HttpClientRequest
        try {
            \function_exists('phasync\ext\sapi') ? \phasync\ext\sapi($code, $this) : \phasync\hooked\sapi($code, $this);
        } finally {
            // Every write of the request is in the pair now: what is left is sent, then the
            // pair goes back for a later request
            $this->ended = true;
            if (null === $this->pump) {
                $this->pumpOutput();
            } else {
                $this->wake();
                \phasync::await($this->pump);
            }
            if (null !== $this->bodyRequest) {
                \fclose($this->bodyRequest);
                \fclose($this->bodyServer);
            }
            --self::$live;
            // Kept for the next request, but a worker with nothing else in flight keeps a single
            // pair: the cache is for concurrency, it is not a hoard of descriptors (a worker that
            // served a burst would otherwise hold two per concurrent request for good).
            if ($this->broken || \count(self::$pairs) >= self::PAIRS_KEPT
                || (0 === self::$live && \count(self::$pairs) > 0)) {
                \fclose($this->request);
                \fclose($this->server);
            } else {
                self::$pairs[] = [$this->request, $this->server];
            }
        }
    }

    public function name(): string
    {
        return 'swerve';
    }

    /**
     * After a 101 was sent (header() and flush()): the raw connection, once the head is on the
     * wire. What the request still echoes goes there unframed.
     */
    public function upgraded(): ClientRequest
    {
        while (null !== $this->pending) {
            \phasync::awaitFlag($this->sent);
        }

        return $this->client;
    }

    /** @return resource where the response body goes: what leaves the output buffers */
    public function output()
    {
        return $this->request;
    }

    /** @return resource|null the request body, to its end; null: none */
    public function input()
    {
        return $this->bodyRequest;
    }

    /**
     * The response head: $headers are raw lines ("Name: value"), as headers_list() returns them;
     * several Set-Cookie lines are kept. $statusLine (a custom reason phrase from
     * header('HTTP/1.1 404 ...')) has nowhere to go: ClientRequest::sendResponseHeaders() takes
     * only the status code, so a custom reason phrase is dropped. A final status is only recorded here (the head leaves with the body); a 1xx head is sent
     * by the output pump. A handler that sent its head on the ClientRequest keeps it.
     */
    public function send_headers(int $status, ?string $statusLine, array $headers): void
    {
        if ($status >= 200 && $this->client->headersSent()) {
            return;
        }
        $map = [];
        foreach ($headers as $line) {
            [$name, $value]                   = \explode(':', $line, 2) + [1 => ''];
            $map[\strtolower(\trim($name))][] = \ltrim($value);
        }
        if ($status >= 200) {
            $this->client->sendResponseHeaders($status, $map);
        } else {
            $this->send(fn () => $this->client->sendResponseHeaders($status, $map));
        }
    }

    /** flush(): the output is on its way already; a head without a body yet goes now. */
    public function flush(): void
    {
        $this->send($this->client->flush(...));
    }

    public function request_info(): array
    {
        $headers = $this->client->getRequestHeaders();
        $target  = $this->client->getTarget();

        return [
            'method'         => $this->client->getMethod(),
            'content_type'   => $headers['content-type'][0] ?? null,
            'content_length' => isset($headers['content-length'][0]) ? (int) $headers['content-length'][0] : null,
            'query_string'   => (string) \parse_url($target, \PHP_URL_QUERY),
            'request_uri'    => $target,
            'protocol'       => $this->client->getProtocolVersion(),
        ];
    }

    public function connection_aborted(): bool
    {
        return $this->client->isClosed();
    }

    /** Has the output pump run $op on the client, in order with the response body. */
    private function send(\Closure $op): void
    {
        $prev          = $this->pending;
        $this->pending = null === $prev ? $op : static function () use ($prev, $op): void {
            $prev();
            $op();
        };
        if (null === $this->pump) {
            $this->pump = \phasync::go($this->pumpOutput(...));
        } else {
            $this->wake();
        }
    }

    /**
     * Write out what the request's script left in the pair, here and now, in order.
     *
     * A PHP shutdown function is where an abandoned request (exit()/die(), swerve#75) finishes its
     * response -- and nothing else runs while PHP does, so the output pump has not had a turn: a body
     * still sitting in the pair is body the client has not been given, and the terminating chunk that
     * handler is about to write would go in front of it. The client then reads a frame that ended
     * before its body: a clean 200 with zero bytes, no error, and an access line that booked its 200
     * at the head. Small pages escape it only because the pump usually gets a turn during their
     * database round trips; a WordPress front page is a dozen writes, and lost about half of them.
     *
     * Taking the pump's last turn inline is safe for exactly that reason: while this runs, no other
     * coroutine can write to this client.
     */
    public function deliver(): int
    {
        $n = 0;
        while (!$this->broken && false !== ($data = \fread($this->server, 65536)) && '' !== $data) {
            $n += \strlen($data);
            $this->client->write($data);
        }
        return $n;
    }

    /** Wakes the output pump if it waits for the response. */
    private function wake(): void
    {
        if ($this->idle) {
            try {
                \phasync::throw($this->pump, new PumpWake());
            } catch (\LogicException) {
                // Woken already (the response became readable): it looks again when it runs
            }
        }
    }

    /** The response, from the pair to the client, until the request has ended and it is all sent. */
    private function pumpOutput(): void
    {
        try {
            while (true) {
                if (null !== $op = $this->pending) {
                    $this->pending = null;
                    $op();
                    \phasync::raiseFlag($this->sent);
                }
                $data = \fread($this->server, 65536);
                if ('' !== $data && false !== $data) {
                    $this->pairEmpty = false;
                    $this->client->write($data);
                    continue;
                }
                $this->pairEmpty = true; // every byte the request wrote is on the client now
                if ($this->ended) {
                    return;
                }
                $this->idle = true;
                try {
                    \phasync::readable($this->server);
                } catch (PumpWake) {
                } finally {
                    $this->idle = false;
                }
            }
        } catch (IOException|TimeoutException) {
            // The client is gone: the request's next write fails, as a SAPI's would.
            $this->broken  = true;
            $this->pending = null;
            \phasync::raiseFlag($this->sent);
            \stream_socket_shutdown($this->server, \STREAM_SHUT_RDWR);
        }
    }

    /** The request body, from the client to the pair, then its end. */
    private function pumpInput(): void
    {
        try {
            while ('' !== $data = $this->client->read(65536)) {
                while ('' !== $data) {
                    // The request ends whenever the script says it has, and that can be while this
                    // pump still holds bytes: the pair is gone, so the body ends here. fwrite() on
                    // a closed stream is a TypeError, which no @ and no IOException catch would stop.
                    if (!\is_resource($this->bodyServer)) {
                        return;
                    }
                    try {
                        $n = @\fwrite($this->bodyServer, $data);
                    } catch (\TypeError) {
                        return;
                    }
                    if (false === $n || 0 === $n) {
                        return; // the request has ended
                    }
                    $data = \substr($data, $n);
                }
            }
        } catch (IOException|TimeoutException) {
            // The client is gone, or the request has ended: the body ends here.
        }
        if (\is_resource($this->bodyServer)) {
            \stream_socket_shutdown($this->bodyServer, \STREAM_SHUT_WR);
        }
    }
}

/**
 * Wakes the output pump for what Sapi::send() left it.
 *
 * @internal
 */
final class PumpWake extends \Exception
{
}
