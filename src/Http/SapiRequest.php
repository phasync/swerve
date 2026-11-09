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

    /** php.ini's output_buffering, once read: see outputBuffering() */
    private static int $buffering;

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

    /**
     * A PSR-7 handler's request (see psr()): its response is the ResponseInterface, written to
     * the ClientRequest; PHP's own head and flush() are not, and output throws.
     */
    private bool $psr;

    /** The request runs in the output buffer of php.ini's output_buffering that run() opened. */
    private bool $buffered;

    private function __construct()
    {
    }

    /**
     * Run $code as a request of $client under sapi(), with $server as its $_SERVER entries.
     */
    public static function run(ClientRequest $client, array $server, \Closure $code): void
    {
        $sapi           = self::popInstance() ?? new self();
        $sapi->client   = $client;
        $sapi->server   = $server;
        $sapi->gone     = $sapi->upgraded = $sapi->finished = $sapi->psr = $sapi->buffered = false;
        $scope          = \Scope::getCurrent();
        self::$requests ??= new \WeakMap();
        self::$requests[$scope] = $sapi;
        $size = self::$buffering ??= self::outputBuffering();
        if (0 !== $size) {
            $inner = $code;
            $code  = static function () use ($inner, $size, $sapi): void {
                // php.ini's default buffer, as php_request_startup() opens it under php-fpm (#111):
                // the CLI forces output_buffering to 0; unless PHP opened it, given with -d
                if (0 === \ob_get_level()) {
                    $sapi->buffered = \ob_start(null, $size > 1 ? $size : 0);
                }
                $inner();
            };
        }
        try {
            \phasync\ext\sapi($code, $sapi);
        } finally {
            unset(self::$requests[$scope]);
            $upgraded = $sapi->upgraded;
            $sapi->returnToPool();
        }
        // Unless upgrade() gave it to a handler (which may keep its WebSocket, say), the code under
        // sapi() never had $client, and SapiRequest let go of it
        if (!$upgraded && $client instanceof HttpClientRequest) {
            $client->reusable();
        }
    }

    /**
     * php.ini's output_buffering, which the CLI that runs swerve forces to 0 over php.ini, even
     * for get_cfg_var(): read from the ini files PHP loaded, or from SWERVE_OUTPUT_BUFFERING, in
     * which the master gives its workers what it read.
     *
     * @internal
     */
    public static function outputBuffering(): int
    {
        $value = \getenv('SWERVE_OUTPUT_BUFFERING');
        if (false === $value) {
            $value = '0';
            foreach (\array_filter([\php_ini_loaded_file(), ...\explode(',', (string) \php_ini_scanned_files())]) as $file) {
                $value = (\parse_ini_file(\trim($file)) ?: [])['output_buffering'] ?? $value;
            }
        }

        return (int) $value;
    }

    public function returnToPool(): void
    {
        unset($this->client, $this->server);
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
     * The request running in this scope is a PSR-7 handler's: its ResponseInterface is the
     * response, which Psr7 writes to the ClientRequest. A flush() of its code before that (a
     * coroutine streaming the body) commits no head of PHP's (#107), and its output throws.
     */
    public static function psr(): void
    {
        $sapi = isset(self::$requests) ? self::$requests[\Scope::getCurrent()] ?? null : null;
        if (null === $sapi) {
            throw new \LogicException('A PSR-7 handler runs inside a request that Swerve serves');
        }
        $sapi->psr = true;
    }

    /**
     * Take the connection off the request running in this scope (see {@see \Swerve\Swerve::upgrade()}).
     *
     * @throws \LogicException outside a request, a second time, or once the response has begun
     */
    public static function upgrade(): void
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
        if ($sapi->buffered && 1 === \ob_get_level()) {
            \ob_end_clean(); // empty: output after this reaches write(), which refuses it
        }
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
        if ($this->psr && '' !== $data) {
            throw new \LogicException('Output in a PSR-7 handler: its response is the ResponseInterface it returns; write to the response body');
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
        if ($this->upgraded || $this->psr) {
            return; // PHP's own headers: the application answers itself
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
        if ($this->upgraded || $this->psr) {
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
