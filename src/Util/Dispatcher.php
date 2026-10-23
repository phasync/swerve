<?php

namespace Swerve\Util;

use phasync\Psr\ServerRequest;
use phasync\Util\Synchronized;
use Swerve\Adapter;
use Swerve\Adapters\ScriptAdapter;
use Swerve\AsyncMode;
use Swerve\ClassicMode;
use Swerve\ClientRequest;
use Swerve\Handlers;
use Swerve\Mode;
use Swerve\PersistentMode;
use Swerve\Util\Classic\Runner;

/**
 * Serves each request of a worker as its {@see Mode} says: routes it ({@see Router}), and serves a
 * file, redirects, hands a Classic script to the {@see Runner}, or runs a handler through its
 * {@see Adapter}.
 *
 * A handler runs in the request's PHP environment: `$_SERVER`, `$_GET` and `$_COOKIE` as PHP-FPM
 * behind nginx fills them, and inside `sapi()` (ext-phasync's, or phasync/hooked's without it),
 * so `echo`, `header()` and the session are the request's own. A `function (): void` handler also gets its body through PHP:
 * `php://input`, and `$_POST` and `$_FILES` for a form. A Persistent application's handler runs one
 * request at a time per worker; async routes overlap with it and with each other.
 *
 * @internal
 */
final class Dispatcher
{
    /** $_SERVER entries of the worker's command line, which no request has. */
    private const COMMAND_LINE = [
        'argv' => 0, 'argc' => 0, 'PHP_SELF' => 0, 'SCRIPT_NAME' => 0, 'SCRIPT_FILENAME' => 0,
        'PATH_TRANSLATED' => 0, 'DOCUMENT_ROOT' => 0, 'REQUEST_TIME' => 0, 'REQUEST_TIME_FLOAT' => 0,
    ];

    private readonly Router $router;

    /** @var list<array{Adapter, object, bool}> per async route: its adapter, its handler, whether it is a script */
    private readonly array $async;

    /** @var array{Adapter, object, bool}|null the application's handler (Persistent, Async) */
    private readonly ?array $app;

    private readonly bool $persistent;

    /** Whether ext-phasync's sapi() and the native request_parse_body() run requests; else phasync/hooked's */
    private readonly bool $ext;

    /**
     * @var array<string, mixed> what every request's $_SERVER starts from: $_SERVER as the application
     *                           left it while booting (its .env values, a framework's flags), without
     *                           the command line's own entries
     */
    private readonly array $server;

    private readonly string $documentRoot;

    /**
     * @param string      $swerveFile the `swerve.php` that returned the mode, the handlers' SCRIPT_FILENAME
     * @param Runner|null $classic    where a Classic application's scripts run
     *
     * @throws \LogicException when an adapter can't be found for a handler, or refuses its mode
     */
    public function __construct(Mode $mode, private readonly string $swerveFile, private readonly ?Runner $classic)
    {
        $this->router = new Router($mode);
        $async        = [];
        $asyncMode    = null;
        foreach ($mode->asyncRoutes() as [, , $handler]) {
            $asyncMode ??= $mode instanceof AsyncMode ? $mode : AsyncMode::serve(null, $handler);
            $adapter = Handlers::resolve($handler, $asyncMode);
            $async[] = [$adapter, $handler, $adapter instanceof ScriptAdapter];
        }
        $this->async = $async;
        if ($mode instanceof PersistentMode || $mode instanceof AsyncMode) {
            $adapter   = Handlers::resolve($mode->handler, $mode);
            $this->app = [$adapter, $mode->handler, $adapter instanceof ScriptAdapter];
        } else {
            $this->app = null;
        }
        if ($mode instanceof ClassicMode && null === $classic) {
            throw new \LogicException('A Classic application needs a runner for its scripts');
        }
        $this->persistent   = $mode instanceof PersistentMode;
        $this->ext          = \function_exists('phasync\ext\sapi');
        // Made after swerve.php ran: what the application put in $_SERVER while booting stays
        $this->server       = \array_diff_key($_SERVER, self::COMMAND_LINE);
        $this->documentRoot = $mode->publicRoot ?? \dirname($swerveFile);
    }

    public function __invoke(ClientRequest $request): void
    {
        $route = $this->router->route($request->getTarget());
        switch ($route[0]) {
            case Router::STATIC:
                StaticFile::send($request, $route[1]);

                return;
            case Router::SCRIPT:
                $this->classic->forward($request, $route[1], $route[2], $route[3]);

                return;
            case Router::ASYNC:
                $this->run($request, $this->async[$route[1]]);

                return;
            case Router::APP:
                if ($this->persistent) {
                    Synchronized::run($this, fn () => $this->run($request, $this->app));
                } else {
                    $this->run($request, $this->app);
                }

                return;
            case Router::REDIRECT:
                $request->sendResponseHeaders(301, ['Location' => $route[1], 'Content-Length' => '0']);
                $request->end();

                return;
            case Router::BAD:
                $request->sendResponseHeaders(400, ['Content-Type' => 'text/plain', 'Content-Length' => '12']);
                $request->write("Bad Request\n");
                $request->end();

                return;
        }
    }

    /**
     * Run a handler in the request's environment.
     *
     * @param array{Adapter, object, bool} $handler
     */
    private function run(ClientRequest $request, array $handler): void
    {
        [$adapter, $object, $script] = $handler;
        $target  = $request->getTarget();
        $q       = \strpos($target, '?');
        $query   = false === $q ? '' : \substr($target, $q + 1);
        $headers = $request->getRequestHeaders();
        $now     = \microtime(true);
        $server  = $this->server;
        foreach ($headers as $name => $values) {
            $server['HTTP_' . \strtoupper(\strtr($name, '-', '_'))] = \implode('cookie' === $name ? '; ' : ', ', $values);
        }
        if (isset($headers['content-type'])) {
            $server['CONTENT_TYPE'] = $headers['content-type'][0];
        }
        if (isset($headers['content-length'])) {
            $server['CONTENT_LENGTH'] = $headers['content-length'][0];
        }
        [$remoteAddr, $remotePort] = self::address($request->peer());
        [$localAddr, $localPort]   = self::address($request->local());
        // The request's own values win over what the application left in $_SERVER while booting
        $server = \array_replace($server, [
            'SERVER_SOFTWARE'    => 'Swerve',
            'GATEWAY_INTERFACE'  => 'CGI/1.1',
            'SERVER_PROTOCOL'    => 'HTTP/' . $request->getProtocolVersion(),
            'SERVER_NAME'        => \preg_replace('/:\d+$/', '', $headers['host'][0] ?? $localAddr),
            'SERVER_ADDR'        => $localAddr,
            'SERVER_PORT'        => $localPort,
            'REMOTE_ADDR'        => $remoteAddr,
            'REMOTE_PORT'        => $remotePort,
            'REQUEST_SCHEME'     => $request->getScheme(),
            'REQUEST_METHOD'     => $request->getMethod(),
            'REQUEST_URI'        => $target,
            'DOCUMENT_URI'       => false === $q ? $target : \substr($target, 0, $q),
            'QUERY_STRING'       => $query,
            'DOCUMENT_ROOT'      => $this->documentRoot,
            'SCRIPT_FILENAME'    => $this->swerveFile,
            'SCRIPT_NAME'        => '',
            'PHP_SELF'           => '',
            'REQUEST_TIME_FLOAT' => $now,
            'REQUEST_TIME'       => (int) $now,
        ]);
        if ('https' === $server['REQUEST_SCHEME']) {
            $server['HTTPS'] = 'on';
        } else {
            unset($server['HTTPS']);
        }
        $_SERVER = $server;
        \parse_str($query, $_GET);
        $_COOKIE  = isset($headers['cookie']) ? ServerRequest::cookies(\implode('; ', $headers['cookie'])) : [];
        $_POST    = [];
        $_FILES   = [];
        $_REQUEST = $_GET;
        $ext      = $this->ext;
        $run      = $script ? static function () use ($adapter, $request, $object, $headers, $ext): void {
            if (\preg_match('#^(?:application/x-www-form-urlencoded|multipart/form-data)\b#i', $headers['content-type'][0] ?? '')) {
                [$_POST, $_FILES] = $ext ? \request_parse_body() : \phasync\hooked\request_parse_body();
                $_REQUEST         = \array_merge($_GET, $_POST);
            }
            $adapter->handle($request, $object);
        } : static fn () => $adapter->handle($request, $object);
        (new Sapi($request, $script))->run($run);
    }

    /** @return array{string, string} an address "host:port" or "[v6]:port" as its host and port */
    private static function address(string $address): array
    {
        return \preg_match('/^\[?(.+?)\]?:(\d+)$/', $address, $m) ? [$m[1], $m[2]] : [$address, ''];
    }
}
