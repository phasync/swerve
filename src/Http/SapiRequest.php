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

    /** The client went away: later writes are refused, as a SAPI's are. */
    private bool $gone;

    /**
     * upgrade() took the connection off the request: output throws, connection_aborted() is
     * true, and PHP's headers are not sent.
     */
    private bool $upgraded;

    /** upgrade()'s closure and the request's state() it runs with, for the connection once the request ended. */
    private ?array $upgrade;

    private function __construct()
    {
    }

    /**
     * Run $code as a request of $client under sapi().
     *
     * @return array{0: \Closure, 1: array}|null upgrade()'s closure and its state(), to run on the
     *                                         connection now that the request ended
     */
    public static function run(ClientRequest $client, \Closure $code): ?array
    {
        $sapi           = self::popInstance() ?? new self();
        $sapi->client   = $client;
        $sapi->gone     = $sapi->upgraded = false;
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
        unset($this->client);
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
        if ($this->upgraded) {
            return; // PHP's own headers, at the request's end: the application answers itself
        }
        $map = [];
        foreach ($headers as $line) {
            [$name, $value]                   = \explode(':', $line, 2) + [1 => ''];
            $map[\strtolower(\trim($name))][] = \ltrim($value);
        }
        $this->client->sendResponseHeaders($status, $map);
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
        ];
    }

    public function connection_aborted(): bool
    {
        // After upgrade() the connection is gone from the request, as if its client had left
        return $this->upgraded || $this->client->isClosed();
    }
}
