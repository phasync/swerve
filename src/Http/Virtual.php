<?php

namespace Swerve\Http;

use phasync;
use phasync\TimeoutException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use phasync\Psr\Response;

/**
 * Run code written for PHP-FPM inside a swerve request: what it echoes, the headers, cookies and
 * status it sets, and its session become the PSR-7 response, streamed as it is produced.
 *
 *     return Virtual::run($request, static function () {
 *         session_start();
 *         header('Content-Type: text/plain');
 *         echo 'Hello ', $_SESSION['name'] ?? 'stranger';
 *     });
 *
 * With phasync-ext's phasync\ext\virtualize(), each run is a request of its own, also while
 * others run in the same worker: output buffers, header() and setcookie(), http_response_code(),
 * the session functions, php://input, shutdown functions, error and exception handlers, and
 * exit(), which ends the request instead of the worker. $_GET, $_POST, $_COOKIE, $_FILES,
 * $_REQUEST and $_SERVER are the request's own, built by PHP from $request as under PHP-FPM; a
 * form body is parsed into $_POST and $_FILES as the request starts. The application's own global
 * variables and static properties are shared by the requests of a worker: run code that keeps
 * request state in them one request at a time.
 *
 * The superglobals and $_SESSION are swapped per request: the request runs in a switch-aware
 * phasync context (Superglobals), so each request sees its own while its coroutines run.
 *
 * Needs phasync-ext 0.5.0-alpha15 or later: see available().
 *
 * @internal
 */
final class Virtual
{
    /** How long output waits for a client that stopped reading before the request is given up. */
    public const WRITE_TIMEOUT = 30.0;

    public static function available(): bool
    {
        return \function_exists('phasync\ext\virtualize') && \version_compare((string) \phpversion('phasync'), '0.5.0-alpha15', '>=');
    }

    /**
     * The response of $code, returned once its headers are sent (at its first output, or when it
     * ends); its body streams on while $code runs.
     *
     * @param \Closure(): mixed $code
     *
     * @throws \LogicException without phasync-ext's virtualize()
     */
    public static function run(ServerRequestInterface $request, \Closure $code): ResponseInterface
    {
        if (!self::available()) {
            throw new \LogicException('Virtual::run() needs phasync-ext 0.5.0-alpha15 or later');
        }
        $sapi = new class($request) {
            public UpgradeStream $body;
            /** @var array{0: int, 1: ?string, 2: list<string>}|null */
            public ?array $head   = null;
            public bool $aborted  = false;

            public function __construct(private readonly ServerRequestInterface $request)
            {
                $this->body = new UpgradeStream();
            }

            public function ub_write(string $data): bool
            {
                if ($this->aborted) {
                    return false;
                }
                try {
                    $this->body->append($data, Virtual::WRITE_TIMEOUT);

                    return true;
                } catch (TimeoutException) {
                    $this->aborted = true; // the client stopped reading

                    return false;
                }
            }

            public function send_headers(int $status, ?string $statusLine, array $headers): void
            {
                $this->head = [$status, $statusLine, $headers];
                phasync::raiseFlag($this);
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

                return [
                    'method'         => $this->request->getMethod(),
                    'content_type'   => $this->request->getHeaderLine('Content-Type') ?: null,
                    'content_length' => '' === $length ? null : (int) $length,
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

            public function connection_aborted(): bool
            {
                return $this->aborted;
            }
        };
        $outer = Superglobals::current(); // the worker's, restored while other requests run
        $run   = phasync::go(static function () use ($code, $sapi, $outer) {
            try {
                // The request runs in a context that swaps its superglobals in and out; PHP builds
                // them as virtualize() starts the request
                phasync::withContext(static fn () => \phasync\ext\virtualize($code, $sapi), new Superglobals($outer));
            } finally {
                $sapi->body->end();
                phasync::raiseFlag($sapi); // in case it ended by throwing, before any headers
            }
        });
        while (null === $sapi->head && !$run->isTerminated()) {
            phasync::awaitFlag($sapi);
        }
        if (null === $sapi->head) {
            phasync::await($run); // it threw before sending anything: the handler's exception
        }
        [$status, $statusLine, $lines] = $sapi->head;
        $headers = [];
        foreach ($lines as $line) {
            [$name, $value]   = \explode(':', $line, 2) + [1 => ''];
            $headers[$name][] = \ltrim($value);
        }
        $reason = null !== $statusLine && \preg_match('/^HTTP\/\S+\s+\d{3}\s+(.+)$/', $statusLine, $m) ? $m[1] : null;

        return new Response($status, $headers, $sapi->body, '1.1', $reason);
    }
}
