<?php

namespace Swerve\Http;

use Psr\Http\Message\ServerRequestInterface;
use Swerve\ClientRequest;
use Swerve\Psr\Psr7;

/**
 * The handler forms Swerve::accept() and Swerve\Server take, made into what a connection runs
 * for each request: a closure taking the ClientRequest.
 *
 * @internal
 */
final class Handler
{
    /**
     * @param string|null $root the static directory, real path: a script handler runs the PHP
     *                          files below it that a request names, as php-fpm behind nginx does
     *
     * @return \Closure(ClientRequest): void
     */
    public static function from(string|\Closure|null $handler, ?string $root): \Closure
    {
        if (!$handler instanceof \Closure) {
            $fallback = null;
            if (null !== $handler) {
                $script = \realpath($handler);
                if (false === $script || !\is_file($script)) {
                    throw new \ValueError("The handler script $handler does not exist");
                }
                $root     ??= \dirname($script);
                $fallback   = [$script, \str_starts_with($script, "$root/") ? \substr($script, \strlen($root)) : '/' . \basename($script), null];
            } elseif (null === $root) {
                throw new \ValueError('Without a handler, $staticPath names the directory to serve');
            }

            return self::sapi($root, static fn (ClientRequest $client) => self::route($client, $root) ?? $fallback, static function (ClientRequest $client, string $file): void {
                \chdir(\dirname($file));
                \phasync\ext\include_global($file);
            });
        }
        $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
        $route  = static fn () => [$script, '/' . \basename($script), null];
        $params = (new \ReflectionFunction($handler))->getParameters();
        if ([] === $params) {
            return self::sapi(\dirname($script), $route, static fn () => $handler());
        }
        $type = $params[0]->getType();
        if ($type instanceof \ReflectionNamedType && ClientRequest::class === $type->getName()) {
            return $handler; // the exchange itself: no superglobals, and the handler writes the response
        }
        if ($type instanceof \ReflectionNamedType && \is_a($type->getName(), ServerRequestInterface::class, true)) {
            return self::sapi(\dirname($script), $route, static fn (ClientRequest $client) => Psr7::serve($client, $handler));
        }
        throw new \TypeError('A handler closure takes no parameters, a ' . ServerRequestInterface::class . ' or a ' . ClientRequest::class);
    }

    /**
     * The PHP file below $root that the request names, as [file, SCRIPT_NAME, PATH_INFO]:
     * `/a/b.php/more` runs a/b.php, `/a/` runs a/index.php; null for any other request. Never a
     * path with a dot-segment (`/.git/x.php`), nor one that leaves $root.
     *
     * @return array{0: string, 1: string, 2: ?string}|null
     */
    private static function route(ClientRequest $client, string $root): ?array
    {
        $path = \rawurldecode((string) \parse_url($client->getTarget(), \PHP_URL_PATH));
        if (\str_contains($path, '/.') || \str_contains($path, "\0")) {
            return null;
        }
        if (\preg_match('#^(/.*?\.php)(/.*)?$#i', $path, $m)) {
            [$name, $info] = [$m[1], $m[2] ?? null];
        } elseif (\str_ends_with($path, '/')) {
            [$name, $info] = [$path . 'index.php', null];
        } else {
            return null;
        }
        $file = \realpath($root . $name);

        return false !== $file && \str_starts_with($file, "$root/") && \is_file($file) ? [$file, $name, $info] : null;
    }


    /**
     * Serve with $code under sapi(), whose request startup gives it the superglobals PHP gives a
     * script, $_SERVER for the script $route names.
     *
     * @param \Closure(ClientRequest): array{0: string, 1: string, 2: ?string} $route the script: [file, SCRIPT_NAME, PATH_INFO]
     * @param \Closure(ClientRequest, string): void                         $code  given the script file
     *
     * @return \Closure(ClientRequest): void
     */
    private static function sapi(string $root, \Closure $route, \Closure $code): \Closure
    {
        return static function (ClientRequest $client) use ($root, $route, $code): void {
            $routed = $route($client);
            if (null === $routed) {
                ErrorPage::send($client, 404);

                return;
            }
            [$file, $name, $info] = $routed;
            SapiRequest::run($client, self::serverVariables($client, $root, $file, $name, $info), static fn () => $code($client, $file));
        };
    }

    /**
     * $_SERVER for a request, as php-fpm gives it by default (clear_env): the request's entries, not
     * the worker's environment, which getenv() reads. PHP adds REQUEST_TIME.
     *
     * @return array<string, mixed>
     */
    private static function serverVariables(ClientRequest $client, string $root, string $script, string $name, ?string $info): array
    {
        $target = $client->getTarget();
        $server = [
            'REQUEST_METHOD'     => $client->getMethod(),
            'REQUEST_URI'        => $target,
            'QUERY_STRING'       => (string) \parse_url($target, \PHP_URL_QUERY),
            'SERVER_PROTOCOL'    => 'HTTP/' . $client->getProtocolVersion(),
            'SCRIPT_FILENAME'    => $script,
            'SCRIPT_NAME'        => $name,
            'PHP_SELF'           => $name . $info,
            'DOCUMENT_ROOT'      => $root,
        ];
        if (null !== $info) {
            $server['PATH_INFO']       = $info;
            $server['PATH_TRANSLATED'] = $root . $info;
        }
        if ('https' === $client->getScheme()) {
            $server['HTTPS'] = 'on';
        }
        if (\preg_match('/^\[?(.+?)\]?:(\d+)$/', $client->peer(), $m)) {
            [, $server['REMOTE_ADDR'], $server['REMOTE_PORT']] = $m;
        }
        if (\preg_match('/^\[?(.+?)\]?:(\d+)$/', $client->local(), $m)) {
            [, $server['SERVER_ADDR'], $server['SERVER_PORT']] = $m;
        }
        foreach ($client->getRequestHeaders() as $name => $values) {
            $key = \strtoupper(\str_replace('-', '_', $name));
            $server[\in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : "HTTP_$key"] = \implode(', ', $values);
        }
        $server['SERVER_NAME'] = \explode(':', $server['HTTP_HOST'] ?? 'localhost')[0];

        return $server;
    }

}
