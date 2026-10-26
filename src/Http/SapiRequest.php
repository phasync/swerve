<?php

namespace Swerve\Http;

use phasync\IOException;
use phasync\TimeoutException;
use Swerve\ClientRequest;
use Swerve\Util\ObjectPoolTrait;

/**
 * The `$sapi` object of `phasync\ext\sapi()` for one request: wraps swerve's
 * {@see ClientRequest}, as a SAPI module wraps the real connection.
 *
 * write() and read() go to the ClientRequest directly, in the request's own coroutine: a write
 * that has to wait for the client suspends the request until it goes through.
 *
 * @internal
 */
final class SapiRequest
{
    use ObjectPoolTrait;

    private ClientRequest $client;

    /** The client went away: later writes are refused, as a SAPI's are. */
    private bool $gone;

    private function __construct()
    {
    }

    /** Run $code as a request of $client under sapi(). */
    public static function run(ClientRequest $client, \Closure $code): mixed
    {
        $sapi         = self::popInstance() ?? new self();
        $sapi->client = $client;
        $sapi->gone   = false;
        try {
            $result = \phasync\ext\sapi($code, $sapi);
        } finally {
            $sapi->returnToPool();
        }
        // The code under sapi() never had $client, and SapiRequest let go of it
        if ($client instanceof HttpClientRequest) {
            $client->reusable();
        }

        return $result;
    }

    public function returnToPool(): void
    {
        unset($this->client);
        $this->pushInstance();
    }

    public function name(): string
    {
        return 'swerve';
    }

    public function write(string $data): bool
    {
        if (!$this->gone) {
            try {
                $this->client->write($data);
            } catch (IOException|TimeoutException) {
                $this->gone = true; // the extension then makes connection_aborted() true
            }
        }

        return !$this->gone;
    }

    public function read(int $length): string
    {
        try {
            return $this->client->read($length);
        } catch (IOException|TimeoutException) {
            return '';
        }
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
