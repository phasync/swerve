<?php

namespace Swerve\Http;

use phasync;
use phasync\IOException;
use phasync\ShutdownException;
use phasync\TimeoutException;
use Swerve\ClientRequest;
use Swerve\HeadersSentException;

/**
 * One HTTP exchange on an {@see HttpConnection}: the request body to read, and the response
 * to frame and write. The connection's coroutine runs the handler on it (run()).
 *
 * The final response head is built when sendResponseHeaders() commits it, and held until the
 * body starts: it goes out in front of the first body bytes in one write, so a small response
 * is one syscall. The framing (Content-Length, chunked, or ending with the connection) is
 * decided then too, from what the handler declared and what the request allows.
 *
 * @internal
 */
final class HttpClientRequest implements ClientRequest
{
    /** How the response body is framed; -1 until the head is complete, see begin(). */
    private const NOBODY  = 0; // 204, 205, 304: no body at all
    private const DISCARD = 1; // HEAD: the body is accepted and dropped
    private const LENGTH  = 2;
    private const CHUNKED = 3;
    private const CLOSE   = 4; // HTTP/1.0 without a length: the body ends at the close

    private const REASONS = [
        100 => 'Continue', 101 => 'Switching Protocols', 102 => 'Processing', 103 => 'Early Hints',
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information', 204 => 'No Content',
        205 => 'Reset Content', 206 => 'Partial Content', 207 => 'Multi-Status', 208 => 'Already Reported', 226 => 'IM Used',
        300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified',
        305 => 'Use Proxy', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 406 => 'Not Acceptable', 407 => 'Proxy Authentication Required', 408 => 'Request Timeout',
        409 => 'Conflict', 410 => 'Gone', 411 => 'Length Required', 412 => 'Precondition Failed', 413 => 'Content Too Large',
        414 => 'URI Too Long', 415 => 'Unsupported Media Type', 416 => 'Range Not Satisfiable', 417 => 'Expectation Failed',
        418 => "I'm a teapot", 421 => 'Misdirected Request', 422 => 'Unprocessable Content', 423 => 'Locked',
        424 => 'Failed Dependency', 425 => 'Too Early', 426 => 'Upgrade Required', 428 => 'Precondition Required',
        429 => 'Too Many Requests', 431 => 'Request Header Fields Too Large', 451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
        504 => 'Gateway Timeout', 505 => 'HTTP Version Not Supported', 506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage', 508 => 'Loop Detected', 510 => 'Not Extended', 511 => 'Network Authentication Required',
    ];

    // The request body: bytes of the current chunk (or of the body) not yet read, and where in it we are
    private int $remaining;
    private bool $chunkEnd = false;
    private bool $done;
    private int $position  = 0;
    private ?HttpError $error = null;

    // The response
    private bool $committed = false;
    private int $status     = 0;
    private string $head    = '';     // the status line and the handler's headers, before the framing
    private ?int $declared  = null;   // the handler's content-length
    private string $options = '';     // the handler's connection options, each after ", "
    private int $framing    = -1;
    private int $left       = 0;      // LENGTH: bytes still to write
    private string $out     = '';     // the complete head, until it is written
    private bool $wire      = false;  // the final head went out: an error can no longer be answered
    private bool $ended     = false;
    private bool $finished  = false;  // run() is over
    private bool $raw       = false;  // after 101
    private ?\Throwable $gone = null; // the client failed or stalled, or close() was called

    private string $client;
    private bool $https = false;

    /**
     * @param array<string, list<string>> $headers
     * @param ?int                        $bodyLength the request body's Content-Length; null: chunked
     */
    public function __construct(
        private readonly HttpConnection $connection,
        private readonly string $method,
        private readonly string $target,
        private readonly string $version,
        private array $headers,
        private readonly ?int $bodyLength,
        private bool $continue,
        private bool $keepAlive,
    ) {
        $this->remaining = $bodyLength ?? 0;
        $this->done      = 0 === $bodyLength;
        $this->client    = $connection->remoteAddr;
        if ($connection->proxied) {
            $this->forwarded();
        }
    }

    /**
     * What the trusted proxy in front says about the client: its address (the last X-Forwarded-For
     * hop that is not a trusted proxy itself; what is before it is only a claim), whether it came by
     * https (X-Forwarded-Proto), and the host it asked for (X-Forwarded-Host, which replaces Host).
     * A value that is not what it should be is ignored.
     */
    private function forwarded(): void
    {
        foreach (\array_reverse(\array_map('trim', \explode(',', \implode(',', $this->headers['x-forwarded-for'] ?? [])))) as $hop) {
            if (\preg_match('/^\[([^\]]+)\](?::\d+)?$/', $hop, $m)) {
                $hop = $m[1];
            } elseif (1 === \substr_count($hop, ':')) {
                $hop = \strstr($hop, ':', true);
            }
            if (false === \filter_var($hop, \FILTER_VALIDATE_IP)) {
                break;
            }
            $this->client = $hop;
            if (!$this->connection->proxies->trusts($hop)) {
                break;
            }
        }
        $host = \trim(\explode(',', $this->headers['x-forwarded-host'][0] ?? '')[0]);
        if ('' !== $host && \strspn($host, HttpConnection::HOST) === \strlen($host)) {
            $this->headers['host'] = [$host];
        }
        $this->https = 'https' === \strtolower(\trim(\explode(',', $this->headers['x-forwarded-proto'][0] ?? '')[0]));
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function getProtocolVersion(): string
    {
        return $this->version;
    }

    public function getScheme(): string
    {
        return $this->https ? 'https' : 'http';
    }

    public function getRequestHeaders(): array
    {
        return $this->headers;
    }

    public function sendResponseHeaders(int $status, array $headers = []): void
    {
        if ($this->committed) {
            throw new HeadersSentException('The response head was already sent');
        }
        if ($status < 100 || $status > 599) {
            throw new \ValueError("$status is not an HTTP status");
        }
        if ($status < 200) {
            $head = $this->build($status, $headers) . "\r\n";
            if (101 === $status) {
                if ('1.1' !== $this->version || !$this->done || !isset($this->headers['upgrade'])
                    || !\in_array('upgrade', \array_map('trim', \explode(',', \strtolower(\implode(',', $this->headers['connection'] ?? [])))), true)) {
                    throw new \LogicException('101 answers an HTTP/1.1 upgrade request (Connection: upgrade, with an Upgrade header) whose body was read to its end');
                }
                $this->committed = $this->wire = $this->raw = true;
                $this->status    = 101;
                $this->keepAlive = false;
                $this->connection->upgraded = true;
                $this->put($head);
            } elseif ('1.1' === $this->version) {
                $this->continue = false; // the client has its go-ahead
                $this->put($head);
            }

            return;
        }
        $this->head      = $this->build($status, $headers);
        $this->status    = $status;
        $this->committed = true;
    }

    public function headersSent(): bool
    {
        return $this->committed;
    }

    public function flush(): void
    {
        if ($this->raw) {
            return;
        }
        $this->begin(false);
        $this->emit();
    }

    public function write(string $bytes, ?float $timeout = null): void
    {
        if ('' === $bytes) {
            return;
        }
        if ($this->raw) {
            $this->put($bytes, $timeout);

            return;
        }
        $this->begin(false);
        switch ($this->framing) {
            case self::CHUNKED:
                $hex   = \dechex(\strlen($bytes)); // one interpolation copies a large body once, a concatenation twice
                $bytes = "$hex\r\n$bytes\r\n";
                break;
            case self::LENGTH:
                if (\strlen($bytes) > $this->left) {
                    throw new \LogicException("The response body is longer than its content-length {$this->declared}");
                }
                $this->left -= \strlen($bytes);
                break;
            case self::DISCARD:
                $bytes = '';
                break;
            case self::NOBODY:
                throw new \LogicException("A {$this->status} response has no body");
        }
        $this->emit($bytes, $timeout);
    }

    public function sendFile($stream, ?int $offset = null, ?int $length = null): void
    {
        $this->begin(false);
        if (self::DISCARD === $this->framing) {
            $this->emit();

            return;
        }
        if (null !== $offset && 0 !== \fseek($stream, $offset)) {
            throw new \RuntimeException("Can't seek to $offset");
        }
        while (null === $length || $length > 0) {
            $chunk = \fread($stream, null === $length ? 65536 : \min(65536, $length));
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $this->write($chunk);
            if (null !== $length) {
                $length -= \strlen($chunk);
            }
        }
        $this->emit(); // an empty file still sends the head
    }

    public function end(?array $trailers = null): void
    {
        if ($this->ended) {
            throw new \LogicException('The response has already ended');
        }
        if ($this->raw) {
            $this->ended = true;
            $this->connection->io->end();

            return;
        }
        $this->begin(null === $trailers);
        $tail = '';
        if (null !== $trailers) {
            if (self::CHUNKED !== $this->framing) {
                throw new \LogicException('Trailers need a chunked response: no content-length, HTTP/1.1, and a status with a body');
            }
            foreach ($trailers as $name => $values) {
                foreach ((array) $values as $value) {
                    if ('' === $name || \strspn($name, HttpConnection::TOKEN) !== \strlen($name) || \strcspn($value, HttpConnection::CTL) !== \strlen($value)) {
                        throw new \UnexpectedValueException("Invalid trailer '$name'");
                    }
                    $tail .= "$name: $value\r\n";
                }
            }
        }
        if (self::CHUNKED === $this->framing) {
            $tail = "0\r\n$tail\r\n";
        } elseif (self::LENGTH === $this->framing && $this->left > 0) {
            $this->connection->logger->warning('Response body ended {left} bytes before its declared size', ['left' => $this->left]);
            $this->keepAlive = false;
        }
        $this->ended = true;
        $this->emit($tail);
    }

    public function read(int $max = 65536, ?float $timeout = null): string
    {
        if ($this->raw) {
            return $this->connection->readRaw($max, $timeout);
        }
        if ($this->finished) {
            throw new \LogicException('The exchange has finished');
        }
        if (null !== $this->error) {
            throw $this->error;
        }
        if ($this->done || $max <= 0) {
            return '';
        }
        try {
            if ($this->continue) {
                // Not once the head went out, or when the client sent its body without waiting
                $this->continue = false;
                if (!$this->wire && $this->connection->bufferEmpty()) {
                    $this->put("HTTP/1.1 100 Continue\r\n\r\n");
                }
            }
            if (null === $this->bodyLength && 0 === $this->remaining) {
                $this->nextChunk(); // at the last chunk, the body ends
                if ($this->done) {
                    return '';
                }
            }
            // What follows a chunk's data is still this body (its CRLF, the next chunk-size
            // line), so a read may take more than the chunk has left
            $bytes            = $this->connection->readBody(\min($max, $this->remaining), null === $this->bodyLength ? \PHP_INT_MAX : $this->remaining, $timeout);
            $n                = \strlen($bytes);
            $this->remaining -= $n;
            $this->position  += $n;
            if (0 === $this->remaining) {
                if (null === $this->bodyLength) {
                    $this->chunkEnd = true;
                } else {
                    $this->done = true;
                }
            }

            return $bytes;
        } catch (HttpError $e) {
            throw $this->error = $e;
        } catch (IOException $e) {
            throw $this->gone = $e;
        } catch (TimeoutException $e) {
            // A timeout of the caller's is theirs to handle; the client's stall ends the exchange
            if (null === $timeout) {
                $this->gone = $e;
            }
            throw $e;
        }
    }

    public function eof(): bool
    {
        return $this->raw ? $this->connection->bufferEmpty() && $this->connection->io->eof() : $this->done;
    }

    public function pending(): bool
    {
        return ($this->raw || !$this->done) && (!$this->connection->bufferEmpty() || $this->connection->io->pending());
    }

    public function close(): void
    {
        $this->gone ??= new IOException('The application closed the connection');
        $this->ended = true;
        $this->connection->io->close();
    }

    public function isClosed(): bool
    {
        return null !== $this->gone || $this->connection->io->isClosed();
    }

    public function peer(): string
    {
        if (!$this->connection->proxied) {
            return $this->connection->io->peer();
        }

        return (\str_contains($this->client, ':') ? "[$this->client]" : $this->client) . ':0';
    }

    public function local(): string
    {
        return $this->connection->io->local();
    }

    /**
     * Run the handler and finish the exchange: end the response if the handler did not, and
     * skip the request body's unread rest, or close. Returns whether the connection stays open.
     */
    public function run(): bool
    {
        $c         = $this->connection;
        $start     = \hrtime(true);
        $keepAlive = false;
        $status    = 500;
        try {
            ($c->handler)($this);
            if (null !== $this->error) {
                throw $this->error; // the handler swallowed a malformed body: answer it, and close
            }
            if (!$this->ended) {
                $this->end();
            }
            $status    = $this->status;
            $keepAlive = $this->conclude();
        } catch (HttpError $e) {
            $status = $e->getCode();
            if (!$this->wire && null === $this->gone) {
                $c->refuse($status, $e->getMessage());
            }
        } catch (ShutdownException) {
            // The worker stops: not a failure. A response not begun is answered 503, in full
            if (!$this->wire && null === $this->gone) {
                phasync::shielded(fn () => $c->refuse(503, 'Service Unavailable', linger: false));
            }
            $status = $this->wire ? $this->status : 503;
        } catch (\Throwable $e) {
            if (null === $this->gone) {
                $c->logger->error('{request} failed: {exception}', ['request' => "$this->method $this->target", 'exception' => $e]);
                if (!$this->wire) {
                    $c->refuse(500, 'Internal Server Error');
                }
            }
            $status = $this->wire ? $this->status : 500;
        } finally {
            $this->finished = $this->ended = true;
            $c->access?->request($this->method, $this->target, $status, (\hrtime(true) - $start) / 1e9);
        }

        return $keepAlive;
    }

    /** After the handler: whether the connection can serve another request. */
    private function conclude(): bool
    {
        $c = $this->connection;
        if (null !== $this->gone) {
            return false;
        }
        if ($this->raw) {
            $c->linger = true; // the client's last bytes get their time to be read

            return false;
        }
        if (!$this->keepAlive) {
            // The client sees the response end now, also a body that ends at the close, while
            // the request's own coroutines go on in the context
            $c->io->end();
            $c->linger = true;

            return false;
        }
        if (!$this->done) {
            $c->discardUntil = \microtime(true) + HttpConnection::DISCARD_TIMEOUT;
            $skipped         = $this->discard(HttpConnection::DISCARD_LIMIT);
            $c->discardUntil = null;
            if (!$skipped) {
                $c->linger = true;

                return false;
            }
        }

        return true;
    }

    /** The unread rest of the request body can be skipped, within $max bytes. */
    private function discardable(int $max): bool
    {
        return $this->done || (null === $this->error && !$this->continue && (null === $this->bodyLength || $this->remaining <= $max));
    }

    private function discard(int $max): bool
    {
        if (!$this->discardable($max)) {
            return false;
        }
        try {
            while (!$this->done) {
                if (($max -= \strlen($this->read(65536))) < 0) {
                    return false;
                }
            }
        } catch (HttpError|IOException|TimeoutException) {
            return false;
        }

        return true;
    }

    /** Read the next chunk-size line of a chunked body, and at the last chunk its trailer. */
    private function nextChunk(): void
    {
        $c = $this->connection;
        if ($this->chunkEnd) {
            $c->readLine(0); // exactly CRLF after the chunk's data
            $this->chunkEnd = false;
        }
        $line   = $c->readLine(HttpConnection::MAX_CHUNK_LINE);
        $digits = \strspn($line, '0123456789abcdefABCDEF');
        // At most 15 hex digits, so the size always fits an int; extensions are accepted and ignored
        if (0 === $digits || $digits > 15 || ($digits !== \strlen($line)
            && 1 !== \preg_match('/\A[ \t]*;[\t\x20-\x7E\x80-\xFF]*\z/', \substr($line, $digits)))) {
            throw new HttpError('Bad Request', 400);
        }
        $this->remaining = \hexdec(\substr($line, 0, $digits));
        if ($this->remaining > $c->maxBodySize - $this->position) {
            throw new HttpError('Content Too Large', 413);
        }
        if (0 === $this->remaining) {
            $total = 0;
            $count = 0;
            while ('' !== ($trailer = $c->readLine(HttpConnection::MAX_CHUNK_LINE))) {
                $total += \strlen($trailer) + 2;
                if (++$count > HttpConnection::MAX_HEADERS || $total > HttpConnection::MAX_HEAD
                    || 1 !== \preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+:[\t\x20-\x7E\x80-\xFF]*\z/', $trailer)) {
                    throw new HttpError('Bad Request', 400);
                }
            }
            $this->done = true;
        }
    }

    /**
     * The head of a response up to its framing: the status line and the handler's headers
     * (checked for what could split the response), Date and Server. For a final response, also
     * notes the handler's content-length and connection options, which decide the framing.
     *
     * Hop-by-hop headers are the connection's, but on a 101, where connection and upgrade
     * are the new protocol's.
     *
     * @param array<string, string|list<string>> $headers
     */
    private function build(int $status, array $headers): string
    {
        $final      = $status >= 200;
        $upgrade    = 101 === $status;
        $head       = "HTTP/1.1 $status " . (self::REASONS[$status] ?? '') . "\r\n";
        $lines      = 1;
        $length     = null;
        $options    = '';
        $chunked    = false;
        $addDate    = $final || $upgrade;
        $addServer  = $addDate;
        foreach ($headers as $name => $values) {
            $values = (array) $values;
            switch (\strtolower($name)) {
                case 'content-length':
                    if ($final) {
                        $v = (string) $values[0];
                        if (!\ctype_digit($v) || \strlen($v) > 18) {
                            throw new \UnexpectedValueException("Invalid content-length '$v'");
                        }
                        $length = (int) $v;
                    }
                    continue 2;
                case 'transfer-encoding':
                    if ($final) {
                        if (0 !== \strcasecmp(\trim(\implode(',', $values)), 'chunked')) {
                            throw new \UnexpectedValueException('The only transfer-encoding supported is chunked, which is what a response without content-length gets');
                        }
                        $chunked = true;
                    }
                    continue 2;
                case 'keep-alive':
                    continue 2;
                case 'connection':
                    if ($upgrade) {
                        break;
                    }
                    if ($final) {
                        // Its options are passed on (an Upgrade needs "upgrade" among them, RFC 9110
                        // 7.8), but close and keep-alive are decided with the framing. Only tokens: the
                        // line is added after the head's check for CR, LF and NUL.
                        foreach ($values as $value) {
                            foreach (\explode(',', (string) $value) as $option) {
                                $option = \trim($option, " \t");
                                if (0 === \strcasecmp($option, 'close')) {
                                    $this->keepAlive = false;
                                } elseif ('' !== $option && 0 !== \strcasecmp($option, 'keep-alive')) {
                                    if (\strspn($option, HttpConnection::TOKEN) !== \strlen($option)) {
                                        throw new \UnexpectedValueException("Response connection option '$option' is not a token");
                                    }
                                    $options .= ", $option";
                                }
                            }
                        }
                    }
                    continue 2;
                case 'date':
                    $addDate = false;
                    break;
                case 'server':
                    $addServer = false;
                    break;
            }
            // Only a token: "Content-Length " or " Folded" would be a second framing header or
            // an obs-fold line to a lenient proxy
            if ('' === $name || \strspn((string) $name, HttpConnection::TOKEN) !== \strlen($name)) {
                throw new \UnexpectedValueException("Response header name '$name' is not a token");
            }
            foreach ($values as $value) {
                $head .= "$name: $value\r\n";
                ++$lines;
            }
        }
        if ($chunked && null !== $length) {
            throw new \UnexpectedValueException('A response has either content-length or transfer-encoding: chunked');
        }
        if ($addDate) {
            $head .= 'Date: ' . HttpConnection::date() . "\r\n";
            ++$lines;
        }
        if ($addServer) {
            $head .= "Server: Swerve\r\n";
            ++$lines;
        }
        // Each line added one CRLF; any other CR, LF or NUL came from the handler and could inject
        // headers or split the response. Three C-level scans over the head.
        if (\substr_count($head, "\n") !== $lines || \substr_count($head, "\r") !== $lines || \str_contains($head, "\0")) {
            throw new \UnexpectedValueException('CR, LF or NUL in a response header');
        }
        if ($final) {
            $this->declared = $length;
            $this->options = $options;
        }

        return $head;
    }

    /**
     * Decide the response's framing and complete its head (once), committing an implicit 200
     * if the handler sent no head.
     *
     * @param bool $empty the response is known to have no body: no length declared means 0
     */
    private function begin(bool $empty): void
    {
        if (!$this->committed) {
            $this->sendResponseHeaders(200);
        }
        if ($this->raw || -1 !== $this->framing) {
            return;
        }
        $head   = $this->head;
        $status = $this->status;
        if ('HEAD' === $this->method || 204 === $status || 205 === $status || 304 === $status) {
            $this->framing = 'HEAD' === $this->method ? self::DISCARD : self::NOBODY;
            $n             = 204 === $status ? null : (205 === $status ? 0 : $this->declared);
            if (null !== $n) {
                $head .= "Content-Length: $n\r\n";
            }
        } elseif (null !== $this->declared || $empty) {
            $this->framing = self::LENGTH;
            $this->left    = $this->declared ?? 0;
            $head         .= 'Content-Length: ' . $this->left . "\r\n";
        } elseif ('1.1' === $this->version) {
            $this->framing = self::CHUNKED;
            $head         .= "Transfer-Encoding: chunked\r\n";
        } else {
            $this->framing   = self::CLOSE;
            $this->keepAlive = false; // HTTP/1.0 and unknown size: the body ends at the close
        }
        // The connection stays open only if the request body is read to its end or may be skipped,
        // and the server isn't draining
        if (!$this->done && ($this->continue || (0 === $this->position && !$this->discardable(HttpConnection::DISCARD_LIMIT)))) {
            $this->keepAlive = false;
        }
        if ($this->connection->draining) {
            $this->keepAlive = false;
        }
        $connection = $this->options . ($this->keepAlive ? ('1.0' === $this->version ? ', keep-alive' : '') : ', close');
        if ('' !== $connection) {
            $head .= 'Connection: ' . \substr($connection, 2) . "\r\n";
        }
        $this->out = "$head\r\n";
    }

    /** Write the complete head, if it is still held, followed by $tail, in one write. */
    private function emit(string $tail = '', ?float $timeout = null): void
    {
        $data = $this->out . $tail;
        if ('' !== $this->out) {
            $this->wire = true;
            $this->out  = '';
        }
        if ('' !== $data) {
            $this->put($data, $timeout);
        }
    }

    /**
     * @throws IOException|TimeoutException when the client is gone or stopped reading
     */
    private function put(string $data, ?float $timeout = null): void
    {
        if (null !== $this->gone) {
            throw new IOException('The client is gone', 0, $this->gone);
        }
        try {
            $this->connection->io->write($data, $this->raw ? $timeout : ($timeout ?? HttpConnection::IO_TIMEOUT));
        } catch (IOException|TimeoutException $e) {
            $this->gone = $e;
            throw $e;
        }
    }
}
