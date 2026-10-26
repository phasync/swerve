<?php

namespace Swerve\Http;

use phasync;
use phasync\IOException;
use phasync\TimeoutException;
use Swerve\ClientRequest;

/**
 * The `$sapi` object of `phasync\ext\sapi()` for one request: wraps swerve's
 * {@see ClientRequest}, as a SAPI module wraps the real connection.
 *
 * The request body and the response body go through socket pairs: the request's end is what
 * output() and input() return, read and written by PHP's own SAPI layer; this end is pumped
 * from and to the client by coroutines. Status and headers stay structured calls. No method
 * the extension calls waits: the client I/O is the pumps'.
 *
 * @internal
 */
final class SapiRequest
{
    /** @var resource the request's end of the response pair */
    private $request;

    /** @var resource this end of it (non-blocking) */
    private $server;

    /** @var resource|null the request body's pair: the request's end, and this end */
    private $bodyRequest = null;
    private $bodyServer  = null;

    /** The request has ended: the pump sends what is left, then stops. */
    private bool $ended = false;

    /** What the pump runs on the client in order with the body (a 1xx head, a flush). */
    private ?\Closure $pending = null;

    private ?\Fiber $pump = null;

    /** The pump waits for the response, and may be woken. */
    private bool $idle = false;

    public function __construct(private readonly ClientRequest $client)
    {
        [$this->request, $this->server] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        \stream_set_timeout($this->request, 86400 * 365);
        \stream_set_blocking($this->server, false);
        if (!$client->eof()) {
            [$this->bodyRequest, $this->bodyServer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
            \stream_set_timeout($this->bodyRequest, 86400 * 365);
            phasync::go($this->pumpInput(...));
        }
    }

    /**
     * Run $code as the request, then send what it left once the pump has it all.
     */
    public function run(\Closure $code): mixed
    {
        $this->pump = phasync::go($this->pumpOutput(...));
        try {
            return \phasync\ext\sapi($code, $this);
        } finally {
            $this->ended = true;
            $this->wake();
            phasync::await($this->pump);
            \fclose($this->request);
            \fclose($this->server);
            if (null !== $this->bodyRequest) {
                \fclose($this->bodyRequest);
                \fclose($this->bodyServer);
            }
        }
    }

    public function name(): string
    {
        return 'swerve';
    }

    /** @return resource */
    public function output()
    {
        return $this->request;
    }

    /** @return resource|null */
    public function input()
    {
        return $this->bodyRequest;
    }

    /**
     * $headers are raw lines, as headers_list() gives them. A final status is recorded (the head
     * leaves with the body); a 1xx head goes now, through the pump. A custom reason phrase
     * ($statusLine) is dropped: ClientRequest takes only the code.
     */
    public function send_headers(int $status, ?string $statusLine, array $headers): void
    {
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
        ];
    }

    public function connection_aborted(): bool
    {
        return $this->client->isClosed();
    }

    private function send(\Closure $op): void
    {
        $prev          = $this->pending;
        $this->pending = null === $prev ? $op : static function () use ($prev, $op): void {
            $prev();
            $op();
        };
        $this->wake();
    }

    private function wake(): void
    {
        if ($this->idle) {
            try {
                phasync::throw($this->pump, new PumpWake());
            } catch (\LogicException) {
                // woken already (the response became readable): it looks again when it runs
            }
        }
    }

    /** The response, from the pair to the client, until the request has ended and all is sent. */
    private function pumpOutput(): void
    {
        try {
            while (true) {
                if (null !== $op = $this->pending) {
                    $this->pending = null;
                    $op();
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
                    phasync::readable($this->server);
                } catch (PumpWake) {
                } finally {
                    $this->idle = false;
                }
            }
        } catch (IOException|TimeoutException) {
            // The client is gone: the request's next write fails, as under a SAPI
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
        }
        if (\is_resource($this->bodyServer)) {
            \stream_socket_shutdown($this->bodyServer, \STREAM_SHUT_WR);
        }
    }
}

/**
 * Wakes the output pump for what SapiRequest::send() left it, or for the request's end.
 *
 * @internal
 */
final class PumpWake extends \Exception
{
}
