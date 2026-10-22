<?php

namespace Swerve\Util\Classic;

use phasync\IOException;
use Swerve\ClientRequest;

/**
 * Runs Classic scripts on ext-phasync's request server, which Swerve's master started: its first
 * process forks one child per connection, and the child serves the connection's requests one at a
 * time with PHP's real request lifecycle.
 *
 * Each worker owns its connections: it opens one when all of its own are busy, up to `$max`, and
 * closes one that stayed idle for {@see IDLE} seconds, which ends its process. A request that finds
 * `$max` busy waits for one. A connection that ends mid-request (its process died) answers 502 when
 * no response had started, and closes the client's connection otherwise; it is not reused.
 *
 * @internal
 */
final class ServerRunner implements Runner
{
    /** Seconds an idle connection stays open. */
    public const IDLE = 10.0;

    /** Seconds a new worker waits for the request server to listen. */
    public const START_TIMEOUT = 10.0;

    /** Response bytes are sent to the server in frames of at most this many. */
    private const CHUNK = 65536;

    /** @var list<Backend> idle connections, the most recently used last */
    private array $idle = [];

    /** Connections open, busy or idle. */
    private int $open = 0;

    /** @var array<int, \stdClass> requests waiting for a connection, first first: each gets ->grant, a connection or true (open one) */
    private array $waiting = [];
    private int $nextWaiter = 0;

    /** Whether a coroutine closes idle connections. */
    private bool $sweeping = false;

    /** The worker's own frame and scope, where the sweeper runs: not a request's, which would wait for it. */
    private readonly \phasync\Frame $frame;
    private readonly \Scope $scope;

    /**
     * Made in the worker's own frame, outside any request.
     *
     * @param int $max this worker's share of the server's processes
     */
    public function __construct(private readonly string $socket, private readonly int $max)
    {
        $this->frame = \phasync::frame();
        $this->scope = \Scope::getCurrent();
    }

    /**
     * Wait until the server accepts connections: the master starts it when the first worker reports
     * a Classic application. The connection is kept, for the first request.
     *
     * @throws \RuntimeException when it doesn't within START_TIMEOUT seconds
     */
    public function awaitServer(): void
    {
        $deadline = \microtime(true) + self::START_TIMEOUT;
        while (true) {
            try {
                $backend = Backend::connect($this->socket);
                break;
            } catch (IOException $e) {
                if (\microtime(true) >= $deadline) {
                    throw new \RuntimeException('The request server did not start within ' . self::START_TIMEOUT . " s: {$e->getMessage()}");
                }
                \phasync::sleep(0.02);
            }
        }
        ++$this->open;
        $backend->idleSince = \microtime(true);
        $this->idle[]       = $backend;
        $this->sweeping     = true;
        \phasync::getLoop()->create($this->sweep(...), [], $this->frame, $this->scope);
    }

    public function forward(ClientRequest $request, string $script, string $scriptName, ?string $pathInfo): void
    {
        $backend = $this->acquire();
        try {
            $this->exchange($backend, $request, $script, $scriptName, $pathInfo);
        } catch (\Throwable $e) {
            $backend->close();
            $this->vacate();
            if (!$e instanceof IOException) {
                throw $e;
            }
            // The server's end failed: the process is gone. The client's own failures are IOExceptions
            // too, and end its exchange the same way.
            if (!$request->headersSent()) {
                $request->sendResponseHeaders(502, ['Content-Type' => 'text/plain', 'Content-Length' => '12']);
                $request->write("Bad Gateway\n");
                $request->end();
            } else {
                $request->close();
            }

            return;
        }
        $this->release($backend);
    }

    private function exchange(Backend $backend, ClientRequest $request, string $script, string $scriptName, ?string $pathInfo): void
    {
        $headers = [];
        foreach ($request->getRequestHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = [$name, $value];
            }
        }
        $hasBody = !$request->eof();
        $head    = [
            'method'      => $request->getMethod(),
            'target'      => $request->getTarget(),
            'protocol'    => 'HTTP/' . $request->getProtocolVersion(),
            'headers'     => $headers,
            'remote'      => self::address($request->peer()),
            'local'       => self::address($request->local()),
            'https'       => 'https' === $request->getScheme(),
            'server_name' => \preg_replace('/:\d+$/', '', $request->getRequestHeaders()['host'][0] ?? ''),
            'body'        => $hasBody,
            'script'      => $script,
            'script_name' => $scriptName,
        ];
        if (null !== $pathInfo) {
            $head['path_info'] = $pathInfo;
        }
        $backend->send([[Backend::HEAD, \serialize($head)]]);

        // The body goes to the server while the response comes back: a script may answer before it
        // reads all of it, or never read it (STOP)
        $pump = null;
        if ($hasBody) {
            $pump = new BodyPump($request, $backend);
            $pump->start();
        }
        try {
            [$type, $payload] = $backend->receive();
            while (Backend::STOP === $type) {
                $pump?->stop();
                [$type, $payload] = $backend->receive();
            }
            if (Backend::HEAD !== $type) {
                throw new IOException("The request server sent a frame of type $type before the response head");
            }
            $response = \unserialize($payload, ['allowed_classes' => false]);
            $out      = [];
            foreach ($response['headers'] as [$name, $value]) {
                $out[\strtolower($name)][] = $value;
            }
            $request->sendResponseHeaders($response['status'], $out);
            while (true) {
                [$type, $payload] = $backend->receive();
                if (Backend::STOP === $type) {
                    $pump?->stop();
                } elseif ('' === $payload) {
                    break;
                } else {
                    $request->write($payload);
                }
            }
            $request->end();
        } finally {
            // The server reads to the end-of-body frame before the next request
            $pump?->finish();
        }
    }

    /**
     * A connection: an idle one or a new one, or, when the worker's share is busy, the first that
     * comes free, in the order the requests asked: a request arriving meanwhile doesn't overtake
     * those waiting.
     */
    private function acquire(): Backend
    {
        if ([] === $this->waiting) {
            if ([] !== $this->idle) {
                return \array_pop($this->idle);
            }
            if ($this->open < $this->max) {
                ++$this->open;

                return $this->connect();
            }
        }
        $id                 = $this->nextWaiter++;
        $waiter             = new \stdClass();
        $waiter->grant      = null;
        $this->waiting[$id] = $waiter;
        try {
            while (null === $waiter->grant) {
                \phasync::awaitFlag($waiter);
            }
        } catch (\Throwable $e) {
            // Given up (the client left): pass on what was granted meanwhile
            unset($this->waiting[$id]);
            if ($waiter->grant instanceof Backend) {
                $this->release($waiter->grant);
            } elseif (true === $waiter->grant) {
                $this->vacate();
            }
            throw $e;
        }

        return true === $waiter->grant ? $this->connect() : $waiter->grant;
    }

    /** A new connection, in a slot counted in $open already. */
    private function connect(): Backend
    {
        try {
            return Backend::connect($this->socket);
        } catch (\Throwable $e) {
            $this->vacate();
            throw $e;
        }
    }

    /** A connection finished a request: to the first waiting request, else idle. */
    private function release(Backend $backend): void
    {
        if ([] !== $this->waiting) {
            $waiter        = \array_shift($this->waiting);
            $waiter->grant = $backend;
            \phasync::raiseFlag($waiter);

            return;
        }
        $backend->idleSince = \microtime(true);
        $this->idle[]       = $backend;
        if (!$this->sweeping) {
            $this->sweeping = true;
            \phasync::getLoop()->create($this->sweep(...), [], $this->frame, $this->scope);
        }
    }

    /** A connection's slot came free: the first waiting request may open one. */
    private function vacate(): void
    {
        if ([] !== $this->waiting) {
            $waiter        = \array_shift($this->waiting);
            $waiter->grant = true;
            \phasync::raiseFlag($waiter);
        } else {
            --$this->open;
        }
    }

    /** Close the connections idle longer than IDLE, until none is idle. */
    private function sweep(): void
    {
        try {
            while ([] !== $this->idle) {
                $now = \microtime(true);
                // The least recently used first
                while ([] !== $this->idle && $now - $this->idle[0]->idleSince >= self::IDLE) {
                    \array_shift($this->idle)->close();
                    --$this->open;
                }
                if ([] !== $this->idle) {
                    \phasync::sleep(self::IDLE - ($now - $this->idle[0]->idleSince));
                }
            }
        } finally {
            $this->sweeping = false;
        }
    }

    /** @return array{string, int} an address "host:port" or "[v6]:port" as its host and port */
    private static function address(string $address): array
    {
        return \preg_match('/^\[?(.+?)\]?:(\d+)$/', $address, $m) ? [$m[1], (int) $m[2]] : [$address, 0];
    }
}
