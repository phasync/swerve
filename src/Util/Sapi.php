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
        if (![$this->request, $this->server] = \array_pop(self::$pairs)) {
            [$this->request, $this->server] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
            // The pumps' client timeouts govern; the request's end only waits for the pump.
            \stream_set_timeout($this->request, 86400 * 365);
            \stream_set_blocking($this->server, false);
        }
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
            if ($this->broken || \count(self::$pairs) >= self::PAIRS_KEPT) {
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
                    $this->client->write($data);
                    continue;
                }
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
                    $n = @\fwrite($this->bodyServer, $data);
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
