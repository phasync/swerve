<?php

namespace Swerve\Http;

use Swerve\ClientRequest;

/**
 * The `$sapi` object of `phasync\ext\sapi()` for one request: wraps swerve's
 * {@see ClientRequest}, as a SAPI module wraps the real connection.
 *
 * The response body and the request body are streams of {@see SapiStream}, which read and
 * write the ClientRequest directly, in the request's own coroutine: a write that has to wait
 * for the client suspends the request until it goes through.
 *
 * @internal
 */
final class SapiRequest
{
    /** @var resource */
    private $output;

    /** @var resource|null */
    private $input = null;

    public function __construct(private readonly ClientRequest $client)
    {
        $context      = \stream_context_create([SapiStream::PROTOCOL => ['client' => $client]]);
        $this->output = SapiStream::open('output', 'w', $context);
        if (!$client->eof()) {
            $this->input = SapiStream::open('input', 'r', $context);
        }
    }

    public function run(\Closure $code): mixed
    {
        try {
            return \phasync\ext\sapi($code, $this);
        } finally {
            \fclose($this->output);
            if (null !== $this->input) {
                \fclose($this->input);
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
        return $this->output;
    }

    /** @return resource|null */
    public function input()
    {
        return $this->input;
    }

    /**
     * $headers are raw lines, as headers_list() gives them. A custom reason phrase
     * ($statusLine) is dropped: ClientRequest takes only the code.
     */
    public function send_headers(int $status, ?string $statusLine, array $headers): void
    {
        $map = [];
        foreach ($headers as $line) {
            [$name, $value]                   = \explode(':', $line, 2) + [1 => ''];
            $map[\strtolower(\trim($name))][] = \ltrim($value);
        }
        $this->client->sendResponseHeaders($status, $map);
    }

    public function flush(): void
    {
        $this->client->flush();
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
}
