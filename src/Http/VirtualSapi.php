<?php

namespace Swerve\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\StreamingResponderInterface;
use Swerve\Swerve;

/**
 * The SAPI of a virtualized request (phasync-ext's virtualize(), see Swerve::virtualize()): what the
 * application echoes goes to the client in pieces of BUFFER bytes and at flush(), as PHP-FPM sends
 * it, with the head the application set up with header(), setcookie() and http_response_code()
 * sent before the first byte.
 *
 * @internal
 */
final class VirtualSapi
{
    /** Output reaches the client in pieces of at least this many bytes, or at flush(). */
    private const BUFFER = 8192;

    /** The application echoed or flushed: its output is the response, and the PSR response it returned (if any) is not. */
    public bool $started = false;

    /** fastcgi_finish_request() ended the response: what the application does from now on is not sent. */
    public bool $finished = false;

    /** What the responder returned when the response ended. */
    public mixed $result = null;

    /** @var array{0: int, 1: string, 2: array<string, list<string>>}|null */
    private ?array $head = null;

    private bool $sent = false;

    private string $buffer = '';

    public function __construct(private readonly ServerRequestInterface $request, private readonly StreamingResponderInterface $responder)
    {
    }

    /** The head, and the output not sent yet: the request ended (also when the code called exit(), or only set headers). */
    public function commit(): void
    {
        if (!$this->finished) {
            $this->drain();
        }
    }

    /** fastcgi_finish_request(): the response ends here, and the request goes on without it. */
    public function finish(): bool
    {
        if ($this->finished) {
            return false;
        }
        $this->started = true;
        $this->drain();
        $this->result   = $this->responder->streamEnd();
        $this->finished = true;

        return true;
    }

    /** The PSR response the handler returned, with the headers the application set up (header(), setcookie(), the session's cookie) that it has none of. */
    public function withHead(ResponseInterface $response): ResponseInterface
    {
        foreach ($this->head[2] ?? [] as $name => $values) {
            $lower = \strtolower($name);
            if ('set-cookie' === $lower) {
                $response = $response->withAddedHeader($name, $values);
            } elseif (!$response->hasHeader($name) && 'content-length' !== $lower && 'transfer-encoding' !== $lower) {
                $response = $response->withHeader($name, $values);
            }
        }

        return $response;
    }

    public function send_headers(int $status, ?string $statusLine, array $headers): void
    {
        $map = [];
        foreach ($headers as $line) {
            [$name, $value]  = \explode(':', $line, 2) + [1 => ''];
            $map[$name][]    = \ltrim($value);
        }
        $reason = null !== $statusLine && \preg_match('/^HTTP\/\S+\s+\d{3}\s+(.+)$/', $statusLine, $m) ? $m[1] : '';
        $this->head = [$status, $reason, $map];
    }

    public function ub_write(string $data): bool
    {
        if ($this->finished) {
            return true;
        }
        $this->started = true;
        if ('' === $this->buffer && \strlen($data) >= self::BUFFER) {
            $this->sent || $this->start();

            return $this->responder->stream($data);
        }
        $this->buffer .= $data;

        return \strlen($this->buffer) < self::BUFFER || $this->drain();
    }

    public function flush(): void
    {
        if (!$this->finished) {
            $this->started = true;
            $this->drain();
        }
    }

    public function connection_aborted(): bool
    {
        return $this->responder->streamGone();
    }

    /** The head, if not sent yet, and the buffered output. */
    private function drain(): bool
    {
        $this->sent || $this->start();
        if ('' === $this->buffer) {
            return true;
        }
        $data         = $this->buffer;
        $this->buffer = '';

        return $this->responder->stream($data);
    }

    private function start(): void
    {
        $this->sent = true;
        [$status, $reason, $headers] = $this->head ?? [200, '', []];
        $this->responder->streamHead($status, $reason, $headers);
    }

    /** Whether PHP parses the body into $_POST and $_FILES: a POST of a form. Any other body is left to the PSR request. */
    public function form(): bool
    {
        if ('POST' !== $this->request->getMethod()) {
            return false;
        }
        $type = \strtolower(\trim(\explode(';', $this->request->getHeaderLine('Content-Type'), 2)[0]));

        return 'application/x-www-form-urlencoded' === $type || 'multipart/form-data' === $type;
    }

    public function read_post(int $length): string
    {
        $body  = $this->request->getBody();
        $bytes = '';
        while (\strlen($bytes) < $length && !$body->eof() && '' !== ($more = $body->read($length - \strlen($bytes)))) {
            $bytes .= $more;
        }

        return $bytes;
    }

    public function request_info(): array
    {
        $length = $this->request->getHeaderLine('Content-Length');
        $form   = $this->form();

        return [
            'method'         => $this->request->getMethod(),
            'content_type'   => $form ? $this->request->getHeaderLine('Content-Type') : null,
            'content_length' => $form && '' !== $length ? (int) $length : null,
            'query_string'   => $this->request->getUri()->getQuery(),
            'request_uri'    => $this->request->getRequestTarget(),
        ];
    }

    public function read_cookies(): ?string
    {
        return $this->request->getHeaderLine('Cookie') ?: null;
    }

    /** $_SERVER, as PHP-FPM fills it: the server's parameters and the request's headers */
    public function register_server_variables(): array
    {
        $server = $this->request->getServerParams();
        foreach ($this->request->getHeaders() as $name => $values) {
            $key          = \strtoupper(\str_replace('-', '_', $name));
            $key          = 'CONTENT_TYPE' === $key || 'CONTENT_LENGTH' === $key ? $key : "HTTP_$key";
            $server[$key] = \implode(', ', $values);
        }
        $server['REQUEST_METHOD'] = $this->request->getMethod();
        $server['REQUEST_URI']    = $this->request->getRequestTarget();
        $server['QUERY_STRING']   = $this->request->getUri()->getQuery();
        foreach (['SERVER_PORT', 'REMOTE_PORT'] as $port) {
            if (isset($server[$port])) {
                $server[$port] = (string) $server[$port]; // strings, as PHP-FPM has them
            }
        }

        // What a web server in front would have supplied (and does, over FastCGI: it comes first)
        $host    = $this->request->getHeaderLine('Host');
        $colon   = \strrpos($host, ':');
        $extras  = [
            'SERVER_NAME'    => false !== $colon && \strlen($host) - 1 !== \strrpos($host, ']') && \substr($host, $colon + 1) === (string) (int) \substr($host, $colon + 1) ? \substr($host, 0, $colon) : $host,
            'REQUEST_SCHEME' => isset($server['HTTPS']) && 'off' !== $server['HTTPS'] ? 'https' : 'http',
        ];
        $auth = $this->request->getHeaderLine('Authorization');
        if (0 === \strncasecmp($auth, 'Basic ', 6) && false !== ($credentials = \base64_decode(\substr($auth, 6), true)) && \str_contains($credentials, ':')) {
            // As PHP does with the Authorization header: PHP_AUTH_USER and PHP_AUTH_PW
            [$extras['PHP_AUTH_USER'], $extras['PHP_AUTH_PW']] = \explode(':', $credentials, 2);
            $extras['AUTH_TYPE']                               = 'Basic';
        } elseif (0 === \strncasecmp($auth, 'Digest ', 7)) {
            $extras['PHP_AUTH_DIGEST'] = \substr($auth, 7);
            $extras['AUTH_TYPE']       = 'Digest';
        }

        return $server + $extras + Swerve::server();
    }
}
