<?php

namespace Swerve\Http;

use Psr\Http\Message\ServerRequestInterface;
use Swerve\StreamingResponderInterface;

/**
 * The SAPI of a virtualized request (phasync-ext's virtualize(), see Swerve::virtualize()): what the
 * application echoes goes to the client as it is made, with the head the application set up with
 * header(), setcookie() and http_response_code() sent before the first byte.
 *
 * @internal
 */
final class VirtualSapi
{
    /** Whether the head was sent: the application's output is the response, and the PSR response it returned (if any) is not. */
    public bool $started = false;

    /** @var array{0: int, 1: string, 2: array<string, list<string>>}|null */
    private ?array $head = null;

    public function __construct(private readonly ServerRequestInterface $request, private readonly StreamingResponderInterface $responder)
    {
    }

    /** The head, if the request ended without any output (the code called exit(), or only set headers). */
    public function commit(): void
    {
        if (!$this->started) {
            $this->start();
        }
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
        $this->started || $this->start();

        return $this->responder->stream($data);
    }

    public function connection_aborted(): bool
    {
        return $this->responder->streamGone();
    }

    private function start(): void
    {
        $this->started = true;
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

        return $server;
    }
}
