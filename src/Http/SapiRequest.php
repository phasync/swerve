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

    /** @var \WeakMap<\Scope, self> the request of each scope, for upgrade() */
    private static \WeakMap $requests;

    private ClientRequest $client;

    /** @var array<string, mixed> the request's $_SERVER entries, for request_info() */
    private array $server;

    /** The client went away: later writes are refused, as a SAPI's are. */
    private bool $gone;

    /**
     * upgrade() took the connection off the request: output throws, connection_aborted() is
     * true, and PHP's headers are not sent.
     */
    private bool $upgraded;

    /** finish() completed the response (or its body reached its content-length): output goes nowhere. */
    private bool $finished;

    /** upgrade()'s closure and the request's state() it runs with, for the connection once the request ended. */
    private ?array $upgrade;

    private function __construct()
    {
    }

    /**
     * Run $code as a request of $client under sapi(), with $server as its $_SERVER entries.
     *
     * @return array{0: \Closure, 1: array}|null upgrade()'s closure and its state(), to run on the
     *                                         connection now that the request ended
     */
    public static function run(ClientRequest $client, array $server, \Closure $code): ?array
    {
        $sapi           = self::popInstance() ?? new self();
        $sapi->client   = $client;
        $sapi->server   = $server;
        $sapi->gone     = $sapi->upgraded = $sapi->finished = false;
        $sapi->upgrade  = null;
        $scope          = \Scope::getCurrent();
        self::$requests ??= new \WeakMap();
        self::$requests[$scope] = $sapi;
        try {
            \phasync\ext\sapi($code, $sapi);
        } finally {
            unset(self::$requests[$scope]);
            $upgrade = $sapi->upgrade;
            $sapi->returnToPool();
        }
        // Unless upgrade() hands it on, the code under sapi() never had $client, and SapiRequest let go of it
        if (null === $upgrade && $client instanceof HttpClientRequest) {
            $client->reusable();
        }

        return $upgrade;
    }

    public function returnToPool(): void
    {
        unset($this->client, $this->server);
        $this->upgrade = null;
        $this->pushInstance();
    }

    /**
     * The exchange of the request running in this scope.
     *
     * @throws \LogicException outside a request
     */
    public static function client(): ClientRequest
    {
        $sapi = isset(self::$requests) ? self::$requests[\Scope::getCurrent()] ?? null : null;

        return $sapi?->client ?? throw new \LogicException('Swerve::upgrade() is called inside a request that Swerve::accept() serves');
    }

    /**
     * Take the connection off the request running in this scope, for $upgrade with $state once
     * the request ended (see {@see \Swerve\Swerve::upgrade()}).
     *
     * @throws \LogicException outside a request, a second time, or once the response has begun
     */
    public static function upgrade(\Closure $upgrade, array $state): void
    {
        $sapi = isset(self::$requests) ? self::$requests[\Scope::getCurrent()] ?? null : null;
        if (null === $sapi) {
            throw new \LogicException('Swerve::upgrade() is called inside a request that Swerve::accept() serves');
        }
        if ($sapi->upgraded) {
            throw new \LogicException('Swerve::upgrade() was called already for this request');
        }
        if (\headers_sent($file, $line)) {
            throw new \LogicException("Swerve::upgrade() is called before the response begins; output began at $file:$line");
        }
        // Output still in a buffer would go out after the connection left
        if (0 !== \array_sum(\array_column(\ob_get_status(true), 'buffer_used'))) {
            throw new \LogicException('Swerve::upgrade() is called before the response begins; there is output in an output buffer');
        }
        $sapi->upgraded = true;
        $sapi->upgrade  = [$upgrade, $state];
    }

    /**
     * Complete the response of the request running in this scope, while its code runs on (see
     * {@see \Swerve\Swerve::finishRequest()}). False outside a request, after upgrade(), or when
     * it was complete already.
     */
    public static function finish(): bool
    {
        $sapi = isset(self::$requests) ? self::$requests[\Scope::getCurrent()] ?? null : null;
        if (null === $sapi || $sapi->upgraded || $sapi->gone || $sapi->finished || !$sapi->client instanceof HttpClientRequest) {
            return false;
        }
        // As php-fpm's fastcgi_finish_request(): what the output buffers hold goes out first
        while (\ob_get_level() > 0 && \ob_end_flush()) {
        }
        \flush();
        $sapi->finished = true; // what the code writes from now on goes nowhere, and connection_aborted() is true

        return $sapi->client->finish();
    }

    /**
     * The current scope's state, to seed() a new Scope with: a new Scope starts empty (the Scope
     * RFC), and the creator gives it what it should have.
     */
    public static function state(): array
    {
        return [
            $_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST, $_SERVER, $_SESSION ?? null,
            \date_default_timezone_get(),
            \class_exists(\Locale::class, false) ? \Locale::getDefault() : null,
        ];
    }

    /** Give the current scope a state() taken elsewhere. */
    public static function seed(array $state): void
    {
        [$_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST, $_SERVER, $session, $timezone, $locale] = $state;
        if (null !== $session) {
            $_SESSION = $session;
        }
        \date_default_timezone_set($timezone);
        if (null !== $locale) {
            \Locale::setDefault($locale);
        }
    }

    public function name(): string
    {
        return 'swerve';
    }

    public function write(string $data): bool
    {
        if ($this->upgraded) {
            throw new \LogicException('Output after Swerve::upgrade(): the connection left the request');
        }
        if ($this->finished) {
            return true; // dropped, as php-fpm does after fastcgi_finish_request(): not a client that left mid-response
        }
        if (!$this->gone) {
            try {
                $this->client->write($data);
            } catch (IOException|TimeoutException) {
                if ($this->client instanceof HttpClientRequest && $this->client->isComplete()) {
                    return $this->finished = true; // its content-length was reached: as after finish()
                }
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
     * $headers are raw lines, as headers_list() gives them; $statusLine is the script's own status
     * line (header('HTTP/1.1 399 Reason')), whose reason phrase goes out as php-fpm sends it.
     */
    public function send_headers(int $status, ?string $statusLine, array $headers): void
    {
        if ($this->upgraded) {
            return; // PHP's own headers, at the request's end: the application answers itself
        }
        // A name as the script first wrote it, as php-fpm sends it; its later lines join it
        $map = $names = [];
        foreach ($headers as $line) {
            [$name, $value] = \explode(':', $line, 2) + [1 => ''];
            $name           = $names[\strtolower($name = \trim($name))] ??= $name;
            $map[$name][]   = \ltrim($value);
        }
        $reason = null === $statusLine ? null : (\explode(' ', \trim($statusLine), 3)[2] ?? null);
        $this->client->sendResponseHeaders($status, $map, $reason);
    }

    public function flush(): void
    {
        if ($this->upgraded) {
            return;
        }
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
            'cookie'         => isset($headers['cookie']) ? \implode('; ', $headers['cookie']) : null,
            'server'         => $this->server,
        ];
    }

    public function connection_aborted(): bool
    {
        // After upgrade() the connection is gone from the request, as if its client had left
        return $this->upgraded || $this->gone || $this->finished || $this->client->isClosed();
    }
}
