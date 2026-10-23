<?php

namespace Swerve\Util\Classic;

use phasync\IOException;
use Swerve\ClientRequest;

/**
 * Runs Classic scripts on ext-phasync's request server, which Swerve's master started. It works
 * as PHP-FPM with `pm = ondemand`: its children accept connections on one socket, so requests
 * from every worker wait in one queue, in arrival order, for the first free child; the server
 * forks a child when none is free, up to its limit, and an idle child exits after 30 seconds.
 *
 * Each request is a connection of its own (a Unix socket: a few microseconds), so no child is
 * tied to a worker. A connection that ends before the response head (its child died) answers
 * 502; after, the client's connection is closed.
 *
 * @internal
 */
final class ServerRunner implements Runner
{
    /** Seconds a new worker waits for the request server to listen. */
    public const START_TIMEOUT = 10.0;

    /**
     * @param string      $socket   the request server's
     * @param string|null $services this worker's service socket, named to every script; null: none
     */
    public function __construct(private readonly string $socket, private readonly ?string $services = null)
    {
    }

    /**
     * Wait until the server listens: the master starts it when the first worker reports a Classic
     * application, and removes a stale socket file first. Its socket file is enough: connecting to
     * find out would make the server fork a child for a connection that carries no request.
     *
     * @throws \RuntimeException when it doesn't within START_TIMEOUT seconds
     */
    public function awaitServer(): void
    {
        $deadline = \microtime(true) + self::START_TIMEOUT;
        while (true) {
            \clearstatcache(true, $this->socket);
            if ('socket' === @\filetype($this->socket)) {
                return;
            }
            if (\microtime(true) >= $deadline) {
                throw new \RuntimeException('The request server did not start within ' . self::START_TIMEOUT . " s: no socket at {$this->socket}");
            }
            \phasync::sleep(0.02);
        }
    }

    public function forward(ClientRequest $request, string $script, string $scriptName, ?string $pathInfo): void
    {
        $backend = null;
        try {
            $backend = Backend::connect($this->socket);
            $this->exchange($backend, $request, $script, $scriptName, $pathInfo);
        } catch (IOException $e) {
            // The server's end failed: the process is gone. The client's own failures are IOExceptions
            // too, and end its exchange the same way.
            if (!$request->headersSent()) {
                $request->sendResponseHeaders(502, ['Content-Type' => 'text/plain', 'Content-Length' => '12']);
                $request->write("Bad Gateway\n");
                $request->end();
            } else {
                $request->close();
            }
        } finally {
            $backend?->close();
        }
    }

    private function exchange(Backend $backend, ClientRequest $request, string $script, string $scriptName, ?string $pathInfo): void
    {
        $headers = [];
        foreach ($request->getRequestHeaders() as $name => $values) {
            if (ServiceServer::HEADER === $name) {
                continue; // only Swerve names the service socket
            }
            foreach ($values as $value) {
                $headers[] = [$name, $value];
            }
        }
        if (null !== $this->services) {
            $headers[] = [ServiceServer::HEADER, $this->services];
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
            // What arrived with the head: a response complete already (a small page, which the
            // server sends at the end in one batch) goes out with a Content-Length in one write
            $body     = '';
            $complete = false;
            while (null !== $frame = $backend->buffered()) {
                if (Backend::STOP === $frame[0]) {
                    $pump?->stop();
                } elseif ('' === $frame[1]) {
                    $complete = true;
                    break;
                } else {
                    $body .= $frame[1];
                }
            }
            $status = $response['status'];
            if ($complete && !isset($out['content-length']) && !isset($out['transfer-encoding'])
                && $status >= 200 && 204 !== $status && 304 !== $status && 'HEAD' !== $request->getMethod()) {
                $out['content-length'] = [(string) \strlen($body)];
            }
            $request->sendResponseHeaders($status, $out);
            if ('' !== $body) {
                $request->write($body);
            }
            while (!$complete) {
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
            // The body ends with its end frame, as the protocol wants, also when the script stopped reading it
            $pump?->finish();
        }
    }

    /** @return array{string, int} an address "host:port" or "[v6]:port" as its host and port */
    private static function address(string $address): array
    {
        return \preg_match('/^\[?(.+?)\]?:(\d+)$/', $address, $m) ? [$m[1], (int) $m[2]] : [$address, 0];
    }
}
