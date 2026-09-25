<?php

namespace Swerve\Http;

use Nyholm\Psr7\ServerRequest;
use phasync;
use phasync\IOException;
use phasync\TimeoutException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * One HTTP/1.1 client connection in --native-http mode: reads a request, calls the
 * application, writes the response, and repeats while the connection is kept alive.
 *
 * Requests on a connection are handled one after another in this connection's coroutine
 * (pipelined requests wait in the read buffer), so there is no coroutine per request.
 */
final class NativeHttpConnection
{
    /** The request line and headers may not be larger than this. */
    private const MAX_HEAD = 65536;

    /** Seconds a kept-alive connection may wait for its next request. */
    private const KEEP_ALIVE_TIMEOUT = 60;

    /** Responses with a known size up to this are written with the head in one write. */
    private const SINGLE_WRITE_LIMIT = 262144;

    private static int $dateTime = 0;
    private static string $date  = '';

    /**
     * Data read from the socket and not yet consumed: the next request head, or the rest
     * of the current request's body.
     */
    public string $buffer = '';

    /**
     * @param resource $socket a connected, non-blocking stream
     */
    public function __construct(
        public readonly mixed $socket,
        private readonly string $peer,
        private readonly RequestHandlerInterface $handler,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function serve(): void
    {
        try {
            do {
                $keepAlive = $this->handleRequest();
            } while ($keepAlive);
        } catch (IOException|TimeoutException) {
            // The client went away or stayed idle too long
        } catch (\Throwable $e) {
            $this->logger->error('{exception}', ['exception' => $e]);
        } finally {
            if (\is_resource($this->socket)) {
                \fclose($this->socket);
            }
        }
    }

    /**
     * Read more from the socket into the buffer, waiting up to $timeout seconds.
     *
     * @throws IOException when the client closed the connection
     */
    public function fill(float $timeout = self::KEEP_ALIVE_TIMEOUT): void
    {
        while (true) {
            $chunk = \fread(phasync::readable($this->socket, $timeout), 65536);
            if (false === $chunk || ('' === $chunk && \feof($this->socket))) {
                throw new IOException('Connection closed by the client');
            }
            if ('' !== $chunk) {
                $this->buffer .= $chunk;

                return;
            }
        }
    }

    /**
     * Handle one request. Returns whether the connection stays open for another.
     */
    private function handleRequest(): bool
    {
        // The request head: request line and headers, up to the empty line
        while (false === ($end = \strpos($this->buffer, "\r\n\r\n"))) {
            if (\strlen($this->buffer) > self::MAX_HEAD) {
                $this->writeError(431, 'Request Header Fields Too Large');

                return false;
            }
            $this->fill();
        }
        if ($end > self::MAX_HEAD) {
            $this->writeError(431, 'Request Header Fields Too Large');

            return false;
        }
        $head         = \substr($this->buffer, 0, $end);
        $this->buffer = \substr($this->buffer, $end + 4);

        $lines       = \explode("\r\n", $head);
        $requestLine = \explode(' ', $lines[0]);
        if (3 !== \count($requestLine) || !\str_starts_with($requestLine[2], 'HTTP/1.')) {
            $this->writeError(400, 'Bad Request');

            return false;
        }
        [$method, $target, $protocol] = $requestLine;
        $version                      = \substr($protocol, 5);

        $headers    = [];
        $connection = '';
        $length     = null;
        $chunked    = false;
        for ($i = 1, $n = \count($lines); $i < $n; ++$i) {
            $colon = \strpos($lines[$i], ':');
            if (false === $colon) {
                $this->writeError(400, 'Bad Request');

                return false;
            }
            $name  = \substr($lines[$i], 0, $colon);
            $value = \trim(\substr($lines[$i], $colon + 1));
            $headers[$name][] = $value;
            switch (\strtolower($name)) {
                case 'content-length':
                    $length = (int) $value;
                    break;
                case 'transfer-encoding':
                    $chunked = \str_contains(\strtolower($value), 'chunked');
                    break;
                case 'connection':
                    $connection = \strtolower($value);
                    break;
            }
        }
        $keepAlive = '1.1' === $version ? 'close' !== $connection : 'keep-alive' === $connection;

        $body    = new RequestBody($this, $chunked ? null : ($length ?? 0), $chunked);
        $now     = \microtime(true);
        $request = new ServerRequest($method, $target, $headers, $body, $version, [
            'REMOTE_ADDR'        => \substr($this->peer, 0, (int) \strrpos($this->peer, ':')),
            'REMOTE_PORT'        => (int) \substr($this->peer, (int) \strrpos($this->peer, ':') + 1),
            'REQUEST_METHOD'     => $method,
            'REQUEST_URI'        => $target,
            'SERVER_PROTOCOL'    => $protocol,
            'REQUEST_TIME'       => (int) $now,
            'REQUEST_TIME_FLOAT' => $now,
        ]);

        $response = $this->handler->handle($request);

        // Skip what the application didn't read, so the next request starts in the right place
        $body->discard();

        $this->writeResponse($response, $keepAlive, 'HEAD' === $method);

        return $keepAlive;
    }

    private function writeResponse(ResponseInterface $response, bool $keepAlive, bool $headOnly): void
    {
        $status = $response->getStatusCode();
        $head   = "HTTP/1.1 $status " . $response->getReasonPhrase() . "\r\n";
        $hasLength = false;
        foreach ($response->getHeaders() as $name => $values) {
            $lower = \strtolower($name);
            if ('content-length' === $lower) {
                $hasLength = true;
            } elseif ('connection' === $lower || 'transfer-encoding' === $lower) {
                continue; // this connection's framing is decided here
            }
            foreach ($values as $value) {
                $head .= "$name: $value\r\n";
            }
        }
        if (self::$dateTime !== ($time = \time())) {
            self::$dateTime = $time;
            self::$date     = \gmdate('D, d M Y H:i:s', $time) . ' GMT';
        }
        $head .= 'Date: ' . self::$date . "\r\n";
        if (!$keepAlive) {
            $head .= "Connection: close\r\n";
        }

        $body = $response->getBody();
        $size = $body->getSize();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        if (null !== $size && $size <= self::SINGLE_WRITE_LIMIT) {
            if (!$hasLength) {
                $head .= "Content-Length: $size\r\n";
            }
            $this->write($head . "\r\n" . ($headOnly ? '' : $body->getContents()));

            return;
        }

        if (null !== $size) {
            if (!$hasLength) {
                $head .= "Content-Length: $size\r\n";
            }
            $this->write($head . "\r\n");
            while (!$headOnly && !$body->eof()) {
                $this->write($body->read(65536));
            }

            return;
        }

        // Unknown size: chunked
        $this->write($head . "Transfer-Encoding: chunked\r\n\r\n");
        while (!$headOnly && !$body->eof()) {
            $chunk = $body->read(65536);
            if ('' !== $chunk) {
                $this->write(\dechex(\strlen($chunk)) . "\r\n" . $chunk . "\r\n");
            }
        }
        $this->write("0\r\n\r\n");
    }

    private function writeError(int $status, string $reason): void
    {
        $this->write("HTTP/1.1 $status $reason\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    }

    private function write(string $data): void
    {
        while ('' !== $data) {
            $written = \fwrite($this->socket, $data);
            if (false === $written) {
                throw new IOException('Connection closed by the client');
            }
            if ($written === \strlen($data)) {
                return;
            }
            $data = \substr($data, $written);
            phasync::writable($this->socket, self::KEEP_ALIVE_TIMEOUT);
        }
    }
}
