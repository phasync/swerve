<?php

/*
 * The application the tests serve, returned the way a project's swerve.php returns it: a loop
 * around Swerve::accept() with a PSR-7 handler (see Swerve::accept()'s docblock for the three
 * handler forms).
 */

use phasync\Psr\Response;
use phasync\Psr\ResourceStream;
use phasync\Psr\StringStream;
use phasync\Psr\UnbufferedStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\EventStreamResponse;
use Swerve\ServerSentEvents;
use Swerve\StopReason;
use Swerve\Swerve;
use Swerve\UpgradeResponse;
use Swerve\WebSocket;
use Swerve\WorkerStoppingException;

/*
 * Supervision tests control how the application loads through the directory in
 * SWERVE_TEST_DIR: a file `crash` there makes every load fail (exit 3), `load-ms` makes it
 * take that long, and `version.php` returns what /version answers; a parse error in it makes
 * the load fail.
 */
$testDir = \getenv('SWERVE_TEST_DIR') ?: null;
if ($segment = \getenv('SWERVE_TEST_SEGMENT')) {
    // The ordered log's segments, short enough to rotate in a test
    \Swerve\Util\OrderedLog::$segment = (float) $segment;
    \Swerve\Util\OrderedLog::$grace   = (float) $segment / 10;
}
if ($testDir && \file_exists("$testDir/crash")) {
    exit(3);
}
if ($testDir && \file_exists("$testDir/load-ms")) {
    \usleep((int) \file_get_contents("$testDir/load-ms") * 1000);
}
$version = $testDir && \file_exists("$testDir/version.php") ? require "$testDir/version.php" : 'none';
if ($testDir && \file_exists("$testDir/record-last-error")) {
    // As frameworks' shutdown handlers report error_get_last(), such as Spiral's
    \register_shutdown_function(static function () use ($testDir) {
        \file_put_contents("$testDir/last-error-" . \getmypid(), \error_get_last()['message'] ?? '');
    });
}

/** A plain response of known size: Content-Length is added by Bridge unless $headers has one. */
function fx_response(int $status, array $headers, string $body): ResponseInterface
{
    return new Response($status, $headers, new StringStream($body));
}

function fx_json(mixed $value): ResponseInterface
{
    return fx_response(200, ['Content-Type' => 'application/json'], \json_encode($value));
}

/**
 * A response whose body is written progressively, from a coroutine of its own ($write gets an
 * UnbufferedStream to append() to, then end()): for the routes that used to write straight to
 * the ClientRequest, sleeping between pieces.
 *
 * @param \Closure(UnbufferedStream): void $write
 */
function fx_stream(int $status, array $headers, \Closure $write): ResponseInterface
{
    $stream = new UnbufferedStream();
    \phasync::go(static function () use ($stream, $write) {
        try {
            $write($stream);
        } finally {
            $stream->end();
        }
    });

    return new Response($status, $headers, $stream);
}

/** The request URI: the scheme and Host, and the target's path and query. */
function fx_uri(ServerRequestInterface $r): string
{
    $uri  = $r->getUri();
    $path = $uri->getPath() . ('' === $uri->getQuery() ? '' : '?' . $uri->getQuery());
    $host = $r->getHeaderLine('host');

    return '' === $host ? $path : $uri->getScheme() . '://' . $host . ('' === $path ? '/' : $path);
}

/** A static property, as frameworks keep request state in them: for /swap. */
final class SwapFixture
{
    public static ?string $value = null;
}

/** The worker's log for the /finally-* routes. */
function &fixture_log(): array
{
    static $log = [];

    return $log;
}

return (function () use ($version) {
    /** @var string[] what /leak keeps */
    $leaked = [];
    /** /sse-beat producers still running. */
    $sseLive = 0;
    /** /websocket-news connections still subscribed, see /news-live. */
    $newsLive = 0;
    /** @var list<\WeakReference> the objects /shutdown-plain's callbacks hold, see /shutdown-probes */
    $probes = [];

    // phasync::onScopeChange(), as a framework adapter would call it at boot, before any
    // request is served: each request's setlocale() and SwapFixture::$value are its own (see
    // /locale-switch and /swap). Behind an env var so only those tests' workers pay for it.
    if (\getenv('SWERVE_TEST_SCOPE_HOOK')) {
        $saved = new \WeakMap();
        phasync::onScopeChange(static function (\Scope $new, ?\Scope $old) use ($saved) {
            if (null !== $old) {
                $saved[$old] = [\setlocale(LC_ALL, 0), SwapFixture::$value];
            }
            [$locale, SwapFixture::$value] = $saved[$new] ?? ['C', null];
            \setlocale(LC_ALL, $locale);
        });
    }

    $handle = function (ServerRequestInterface $r) use ($version, &$leaked, &$sseLive, &$newsLive, &$probes): ResponseInterface {
        $path  = $r->getUri()->getPath();
        $query = $r->getQueryParams();

        switch ($path) {
            case '/hello':
                return fx_response(200, ['Content-Type' => 'text/plain'], 'Hello');
            case '/save-on-stop':
                // A coroutine that outlives its request and saves its work when stopped, with cleanup that waits
                $file = (string) $query['file'];
                phasync::go(static function () use ($file) {
                    phasync::finally(static function () use ($file) {
                        phasync::sleep(0.2);
                        \file_put_contents($file, 'saved');
                    });
                    phasync::sleep(100);
                });

                return fx_response(200, ['Content-Type' => 'text/plain'], 'started');
            case '/background':
                // A coroutine that outlives its request, and says why it was stopped
                $file = (string) $query['file'];
                phasync::go(static function () use ($file) {
                    try {
                        phasync::sleep(100);
                    } catch (WorkerStoppingException $e) {
                        \file_put_contents($file, $e->reason->name);
                    }
                });

                return fx_response(200, ['Content-Type' => 'text/plain'], 'started');
            case '/global':
                return fx_response(200, ['Content-Type' => 'text/plain'], (string) ($GLOBALS[(string) $query['k']] ?? ''));
            case '/ini':
                return fx_response(200, ['Content-Type' => 'text/plain'], (string) \ini_get((string) $query['k']));
            case '/echo':
                return fx_response(200, ['Content-Type' => 'text/plain'], (string) $r->getBody());
            case '/big':
                return fx_response(200, ['Content-Type' => 'text/plain'], \str_repeat('x', (int) $query['n']));
            // A static kept per request, as an adapter keeps its framework's, by the hook registered
            // at boot (SWERVE_TEST_SCOPE_HOOK). ?v= set, read back after waiting ?ms=
            case '/swap':
                SwapFixture::$value = $query['v'];
                phasync::sleep((int) $query['ms'] / 1000);

                return fx_response(200, [], (string) SwapFixture::$value);
            // setlocale() is this request's own, through every wait, by the hook registered at boot
            // (SWERVE_TEST_SCOPE_HOOK). ?v= set, read back after waiting ?ms=
            case '/locale-switch':
                \setlocale(LC_ALL, (string) $query['v']);
                phasync::sleep((int) $query['ms'] / 1000);

                return fx_response(200, [], (string) \setlocale(LC_ALL, 0));
            // A WebSocket that sends what is published to 'news'
            case '/websocket-news':
                return \Swerve\WebSocketResponse::from($r, static function (WebSocket $ws) use (&$newsLive) {
                    ++$newsLive;
                    try {
                        foreach (Swerve::subscribe('news') as $message) {
                            $ws->send($message);
                        }
                    } finally {
                        --$newsLive;
                    }
                });
            // A WebSocket that tells its worker's pid, then echoes
            case '/websocket-shutdown':
                return \Swerve\WebSocketResponse::from($r, static function (WebSocket $ws) {
                    $ws->send((string) \getmypid());
                    foreach ($ws as $message) {
                        $ws->isBinary() ? $ws->sendBinary($message) : $ws->send($message);
                    }
                });
            case '/ws':
                return \Swerve\WebSocketResponse::from($r, static function (WebSocket $ws) use ($query) {
                    if (!empty($query['bye'])) {
                        $ws->end(1000);

                        return;
                    }
                    foreach ($ws as $message) {
                        $ws->isBinary() ? $ws->sendBinary($message) : $ws->send($message);
                    }
                });
            case '/news-live':
                return fx_json([\getmypid(), $newsLive]);
            // A request that ends having registered a callback, which must then never run: it holds an object (see /shutdown-probes)
            case '/shutdown-plain':
                $probe    = new \stdClass();
                $probes[] = \WeakReference::create($probe);
                Swerve::onShutdown(static function () use ($probe) {
                    Swerve::log()->notice('onShutdown callback of a request that ended ran');
                });

                return fx_response(200, [], 'registered');
            // How many of /shutdown-plain's objects are still alive, after a garbage collection
            case '/shutdown-probes':
                \phasync::sleep(0.6); // the event loop collects cycles half a second after a coroutine ends
                \gc_collect_cycles();

                return fx_json(\count(\array_filter($probes, static fn (\WeakReference $w) => null !== $w->get())));
            // Swerve::cache(): ?k= and ?v= (JSON), ?ttl= seconds; answers with the worker's pid
            case '/cache-set':
                return fx_json([\getmypid(), Swerve::cache()->set($query['k'], \json_decode($query['v'], true), isset($query['ttl']) ? (int) $query['ttl'] : null)]);
            case '/cache-get':
                return fx_json([\getmypid(), Swerve::cache()->get($query['k'], 'missing')]);
            case '/cache-del':
                return fx_json([\getmypid(), Swerve::cache()->delete($query['k'])]);
            case '/cache-clear':
                return fx_json([\getmypid(), Swerve::cache()->clear()]);
            // Many lookups at once from one request's coroutines: each gets its own answer
            case '/cache-many':
                $cache      = Swerve::cache();
                $cache->setMultiple(\array_combine(\array_map(fn ($i) => "many$i", \range(1, 50)), \range(1, 50)));
                $coroutines = \array_map(fn ($i) => phasync::go(fn () => $cache->get("many$i")), \range(1, 50));

                return fx_json(\array_map(phasync::await(...), $coroutines));
            // Work after the response: writes "done" to $SWERVE_TEST_DIR/after-response ?ms= later
            case '/after-response':
                phasync::go(static function () use ($query) {
                    phasync::sleep((int) $query['ms'] / 1000);
                    \file_put_contents(\getenv('SWERVE_TEST_DIR') . '/after-response', 'done');
                });

                return fx_response(200, [], 'ok');
            case '/sleep':
                phasync::sleep(((int) $query['ms']) / 1000);

                return fx_response(200, ['Content-Type' => 'text/plain'], 'slept ' . $query['id']);
            // A request no stop can end: it sleeps shielded
            case '/stubborn':
                phasync::shielded(static fn () => phasync::sleep(((int) $query['ms']) / 1000));

                return fx_response(200, ['Content-Type' => 'text/plain'], 'slept');
            case '/params':
                $cookies = [];
                foreach (\explode(';', \implode(';', $r->getHeader('cookie'))) as $pair) {
                    if (2 === \count($kv = \explode('=', \trim($pair), 2)) && !isset($cookies[$kv[0]])) {
                        $cookies[$kv[0]] = \urldecode($kv[1]);
                    }
                }

                return fx_json([
                    'method'  => $r->getMethod(),
                    'target'  => $r->getRequestTarget(),
                    'uri'     => fx_uri($r),
                    'host'    => $r->getHeaderLine('host'),
                    'cookies' => $cookies,
                    'headers' => \array_map(static fn (array $v) => \implode(', ', $v), $r->getHeaders()),
                ]);
            // The largest piece one read(65536) of the request body returned
            case '/read-sizes':
                $body = $r->getBody();
                $max  = 0;
                while ('' !== ($piece = $body->read(65536))) {
                    $max = \max($max, \strlen($piece));
                }

                return fx_response(200, [], (string) $max);
            // A body over a stream that another coroutine of this worker appends to later (after
            // ?ms=, 200 by default): read() returns '' until then. ?cl= also declares its length.
            case '/nonblocking':
                return fx_stream(200, isset($query['cl']) ? ['Content-Length' => $query['cl']] : [], static function (UnbufferedStream $stream) use ($query) {
                    phasync::sleep(((int) ($query['ms'] ?? 200)) / 1000);
                    $stream->append('late data');
                });
            // Streams whose size is 0 although they have data
            case '/pipe':
                $pipe = \popen('echo from-pipe', 'r');
                \stream_set_blocking($pipe, false);

                return new Response(200, [], new ResourceStream($pipe));
            case '/proc':
                $proc = \fopen('/proc/self/stat', 'r');
                \stream_set_blocking($proc, false);

                return new Response(200, [], new ResourceStream($proc));
            // phasync::finally() in the handler: runs after the whole (streamed) response, before the
            // next request on the connection
            case '/finally-stream':
                $log   = &fixture_log();
                $fiber = Fiber::getCurrent();
                phasync::finally(static function () use (&$log, $fiber) {
                    phasync::sleep(0.01); // it may wait
                    $log[] = 'finally ran in the ' . (Fiber::getCurrent() === $fiber ? "request's coroutine" : 'another coroutine');
                });

                return fx_stream(200, [], static function (UnbufferedStream $stream) use (&$log) {
                    foreach (['a', 'b', 'c'] as $chunk) {
                        phasync::sleep(0.02);
                        $stream->append($chunk);
                    }
                    $log[] = 'body ended';
                });
            case '/finally-log':
                $log  = &fixture_log();
                $json = \json_encode($log);
                $log  = [];

                return fx_response(200, ['Content-Type' => 'application/json'], $json);
            // ?n= pieces of 10 bytes ("piece 000\n", ...), ?ms= apart; ?size=1 declares the length, ?throw=1 fails at the third
            case '/stream':
                $n  = (int) $query['n'];
                $ms = (int) ($query['ms'] ?? 0);

                return fx_stream(200, !empty($query['size']) ? ['Content-Length' => (string) (10 * $n)] : [], static function (UnbufferedStream $stream) use ($n, $ms, $query) {
                    for ($i = 0; $i < $n; ++$i) {
                        if ($i > 0) {
                            phasync::sleep($ms / 1000);
                        }
                        if (!empty($query['throw']) && 2 === $i) {
                            throw new RuntimeException('failed while streaming');
                        }
                        $stream->append(\sprintf("piece %03d\n", $i));
                    }
                });
            // Declares ?cl= bytes, and sends ?actual= pieces of 10
            case '/applength':
                return fx_stream(200, ['Content-Length' => $query['cl']], static function (UnbufferedStream $stream) use ($query) {
                    for ($i = 0; $i < (int) $query['actual']; ++$i) {
                        $stream->append(\sprintf("piece %03d\n", $i));
                    }
                });
            case '/echo-stream':
                $length = $r->getHeaderLine('content-length');

                return fx_stream(200, '' !== $length ? ['Content-Length' => $length] : [], static function (UnbufferedStream $stream) use ($r) {
                    $body = $r->getBody();
                    while ('' !== ($piece = $body->read(65536))) {
                        $stream->append($piece);
                    }
                });
            case '/first':
                $body = $r->getBody();
                $data = '';
                while (\strlen($data) < (int) $query['n']) {
                    $data .= $body->read((int) $query['n'] - \strlen($data));
                }

                return fx_response(200, [], $data);
            // "prefix\n" first, then the request body: it is only read after the head was sent
            case '/lazy-echo':
                return fx_stream(200, [], static function (UnbufferedStream $stream) use ($r) {
                    $stream->append("prefix\n");
                    $body = $r->getBody();
                    while ('' !== ($piece = $body->read(65536))) {
                        $stream->append($piece);
                    }
                });
            // The request body, echoed after its first 3 bytes were read
            case '/partial':
                $body = $r->getBody();
                $body->read(3);

                return fx_stream(200, ['Content-Length' => (string) ((int) $r->getHeaderLine('content-length') - 3)], static function (UnbufferedStream $stream) use ($body) {
                    while ('' !== ($piece = $body->read(65536))) {
                        $stream->append($piece);
                    }
                });
            case '/status':
                $code = (int) $query['code'];

                return \in_array($code, [204, 205, 304], true) ? new Response($code) : fx_response($code, [], 'x');
            case '/throw':
                throw new RuntimeException('boom');
            // Faults for the supervision tests
            case '/pid':
                return fx_response(200, [], (string) \getmypid());
            case '/version':
                return fx_response(200, [], $version);
            case '/exit':
                exit((int) ($query['code'] ?? 3));
            // With ?small=1, in many small pieces, which makes PHP's shutdown after it slow
            case '/oom':
                for ($i = 0; isset($query['small']); ++$i) {
                    $leaked[] = "x$i";
                }
                $s = 'x';
                while (true) {
                    $s .= $s;
                }
            // Keeps ?kb= KiB for good, after sleeping ?ms=: a steady leak
            case '/leak':
                phasync::sleep(((int) ($query['ms'] ?? 0)) / 1000);
                $leaked[] = \str_repeat('x', (int) $query['kb'] * 1024);

                return fx_response(200, [], (string) \memory_get_usage(true));
            // Uninterruptible: with ext-phasync, checkpoint preemption would let other
            // coroutines (the heartbeat among them) run between iterations, healing the
            // very stall this route exists to simulate for the watchdog tests.
            case '/spin':
                (#[\phasync\Uninterruptible] static function () {
                    while (true) {
                    }
                })();

                throw new \LogicException('unreachable');
            // A blocking sleep of ?ms=, answering how long it took: a signal would end it early
            case '/usleep':
                $start = \microtime(true);
                \usleep((int) $query['ms'] * 1000);

                return fx_response(200, [], \sprintf('%.2f', \microtime(true) - $start));
            // A background job that outlives the request (and the worker), for ?s= seconds
            case '/spawn':
                \exec('sleep ' . (int) ($query['s'] ?? 30) . ' > /dev/null 2>&1 &');

                return fx_response(200, [], 'spawned');
            // A background coroutine that fails after the response was sent
            case '/bgthrow':
                phasync::go(static function () {
                    phasync::sleep(0.2);

                    throw new RuntimeException('background boom');
                });

                return fx_response(200, [], 'ok');
            // Headers that are not valid: ?kind= value, name, token (?name=)
            case '/bad':
                return new Response(200, match ($query['kind']) {
                    'value' => ['X-A' => ["a\r\nX-Evil: 1"]],
                    'name'  => ["X-Evil: 1\r\nX-B" => ['v']],
                    'token' => [$query['name'] => ['x']],
                });
            // An upgrade refused: 426 names the protocol it requires (RFC 9110 15.5.22)
            case '/refuse-upgrade':
                return fx_response(426, ['Upgrade' => 'websocket', 'Connection' => 'Upgrade', 'Sec-WebSocket-Version' => '13'], 'upgrade required');
            // The body upper-cased as it arrives, while it arrives
            case '/duplex':
                return fx_stream(200, [], static function (UnbufferedStream $stream) use ($r) {
                    $body = $r->getBody();
                    while ('' !== ($piece = $body->read(8192))) {
                        $stream->append(\strtoupper($piece));
                    }
                });
            // The purest tunnel: what the client sends comes back
            case '/upgrade-echo':
                return UpgradeResponse::from($r, ['Upgrade' => 'echo', 'Connection' => 'Upgrade'], static function (\phasync\Net\Duplex $conn) {
                    while ('' !== ($piece = $conn->read())) {
                        $conn->write($piece);
                    }
                });
            // A tunnel sending ?kb= KiB and "END", whose input is never read; with ?read=1, read to its end afterwards
            case '/upgrade-hold':
                return UpgradeResponse::from($r, ['Upgrade' => 'x', 'Connection' => 'Upgrade'], static function (\phasync\Net\Duplex $conn) use ($query) {
                    $conn->write(\str_repeat('y', 1024 * (int) $query['kb']) . 'END');
                    $conn->end();
                    if (!empty($query['read'])) {
                        while ('' !== $conn->read(65536)) {
                        }
                    }
                });
            // A tunnel answering each piece upper-cased; "q" says bye and ends it, the end of
            // the client's side says EOF and ends it, unless ?ignore-eof=1. ?wait= ms before the 101.
            case '/upgrade-upper':
                phasync::sleep((int) ($query['wait'] ?? 0) / 1000);

                return UpgradeResponse::from($r, ['Upgrade' => 'upper', 'Connection' => 'Upgrade'], static function (\phasync\Net\Duplex $conn) use ($query) {
                    while ('' !== ($piece = $conn->read(65536))) {
                        if (\str_ends_with($piece, 'q')) {
                            $conn->write(\strtoupper(\substr($piece, 0, -1)) . "bye\n");
                            $conn->end();

                            return;
                        }
                        $conn->write(\strtoupper($piece));
                    }
                    if (empty($query['ignore-eof'])) {
                        $conn->write("EOF\n");
                        $conn->end();

                        return;
                    }
                    while (true) {
                        phasync::sleep(1);
                    }
                });
            // A tunnel sending ?mb= MiB, which the application gives up ?ms= after the 101 (a
            // client that no longer answers its pings): it closes the connection
            case '/upgrade-abort':
                return UpgradeResponse::from($r, ['Upgrade' => 'x', 'Connection' => 'Upgrade'], static function (\phasync\Net\Duplex $conn) use ($query) {
                    phasync::go(static function () use ($conn, $query) {
                        phasync::sleep((int) $query['ms'] / 1000);
                        $conn->close();
                    });
                    try {
                        $piece = \str_repeat('x', 65536);
                        for ($i = 16 * (int) $query['mb']; $i > 0; --$i) {
                            $conn->write($piece);
                        }
                    } catch (\phasync\IOException) {
                    }
                });
            // Server-Sent Events: ?n= events, ?ms= apart
            case '/sse':
                return EventStreamResponse::stream(static function (ServerSentEvents $sse) use ($query) {
                    for ($i = 0; $i < (int) $query['n']; ++$i) {
                        if ($i > 0) {
                            phasync::sleep((int) $query['ms'] / 1000);
                        }
                        $sse->send((string) $i);
                    }
                });
            // Server-Sent Events that learn their client left: a heartbeat every 100 ms, until a
            // write throws. /sse-live counts the producers still running.
            case '/sse-beat':
                return EventStreamResponse::stream(static function (ServerSentEvents $sse) use (&$sseLive) {
                    ++$sseLive;
                    try {
                        while (true) {
                            $sse->comment('beat');
                            phasync::sleep(0.1);
                        }
                    } catch (\phasync\IOException) {
                    } finally {
                        --$sseLive;
                    }
                });
            case '/sse-live':
                return fx_response(200, [], (string) $sseLive);
            // Swerve::subscribe() (?ordered=1: an OrderedChannel) as Server-Sent Events: "ready <pid>"
            // once subscribed, then ?n= messages of ?topic=; ?maxlag= seconds, ?stall= seconds before
            // the first is read; "lag" if the subscription throws SubscriberLagException
            case '/subscribe':
                return EventStreamResponse::stream(static function (ServerSentEvents $sse) use ($query) {
                    $maxLag       = (float) ($query['maxlag'] ?? 30);
                    $subscription = isset($query['ordered']) ? (new \Swerve\OrderedChannel($query['topic']))->subscribe($maxLag) : Swerve::subscribe($query['topic'], $maxLag);
                    $sse->send('ready ' . \getmypid());
                    $i = 0;
                    if (isset($query['stall'])) {
                        phasync::sleep((float) $query['stall']);
                    }
                    try {
                        foreach ($subscription as $message) {
                            // ?hex=1: the message as hex, so arbitrary bytes from publishRaw() survive the SSE framing
                            $sse->send(isset($query['hex']) ? \bin2hex($message) : (\is_string($message) ? $message : 'json ' . \json_encode($message)));
                            if (++$i >= (int) $query['n']) {
                                break;
                            }
                        }
                    } catch (\Swerve\SubscriberLagException) {
                        $sse->send('lag');
                    }
                });
            // A structured message, sent as JSON: ['m' => ?m, 'n' => 1, 'list' => [1, 2]]
            case '/publish-json':
                Swerve::publish($query['topic'], ['m' => $query['m'], 'n' => 1, 'list' => [1, 2]]);

                return fx_response(200, [], 'published');
            // ?count= messages "<?id>:<i>:" and ?size= (0) more bytes to ?topic=, ?sleep= ms (0.5) apart, with
            // an OrderedChannel for ?ordered=1; answers the pid
            case '/publish-seq':
                for ($i = 0; $i < (int) $query['count']; ++$i) {
                    $message = $query['id'] . ':' . $i . ':' . \str_repeat((string) ($i % 10), (int) ($query['size'] ?? 0));
                    isset($query['ordered']) ? (new \Swerve\OrderedChannel($query['topic']))->write($message) : Swerve::publish($query['topic'], $message);
                    phasync::sleep((float) ($query['sleep'] ?? 0.5) / 1000);
                }

                return fx_response(200, [], (string) \getmypid());
            case '/publish-ordered':
                (new \Swerve\OrderedChannel($query['topic']))->write($query['m']);

                return fx_response(200, [], 'published');
            // Blocks the worker's event loop for ?ms= (a busy wait: with ext-phasync, usleep() yields)
            case '/block':
                (#[\phasync\Uninterruptible] static function () use ($query) {
                    for ($end = \microtime(true) + (int) $query['ms'] / 1000; \microtime(true) < $end;) {
                    }
                })();

                return fx_response(200, [], 'blocked');
            case '/publish-end':
                Swerve::publish($query['topic'], ['end' => true]);

                return fx_response(200, [], 'published');
            case '/publish':
                Swerve::publish($query['topic'], \str_repeat($query['m'], (int) ($query['times'] ?? 1)));

                return fx_response(200, [], 'published');
            // ?m= as hex: Swerve::publishRaw(), for bytes a query string can't carry directly
            case '/publish-raw':
                Swerve::publishRaw($query['topic'], \hex2bin($query['m']));

                return fx_response(200, [], 'published');
            // Publishes ?topic= from a coroutine's finally, after cancelling that coroutine first:
            // the publish must still be sent, even though its coroutine no longer runs
            case '/publish-from-cancelled':
                $fiber = phasync::go(static function () use ($query) {
                    try {
                        phasync::sleep(100);
                    } finally {
                        Swerve::publish($query['topic'], 'goodbye');
                    }
                });
                phasync::cancel($fiber);
                try {
                    phasync::await($fiber);
                } catch (\phasync\CancelledException) {
                }

                return fx_response(200, [], 'done');
            // ?n= messages "msg<i>" to ?topic=, back to back with no wait in between: on this
            // topic's first publish in this worker, every one of them queues before the first
            // reaches the master, so their order out of that queue is what is tested
            case '/publish-burst':
                for ($i = 0; $i < (int) $query['n']; ++$i) {
                    Swerve::publish($query['topic'], "msg$i");
                }

                return fx_response(200, [], 'done');
            // Whether Swerve::publish() of ?topic= (never published before in this worker)
            // suspended the coroutine that called it: go() runs a coroutine that never waits to
            // completion before returning, so isTerminated() answers without waiting for it
            case '/publish-non-suspending':
                $fiber = phasync::go(static function () use ($query) {
                    Swerve::publish($query['topic'], 'hello');
                });

                return fx_response(200, [], $fiber->isTerminated() ? 'true' : 'false');
            case '/app-log':
                Swerve::log()->warning('from the application: {what}', ['what' => 'hi']);

                return fx_response(200, [], 'logged');
            case '/topics':
                return fx_response(200, [], \implode(',', Swerve\Util\Topics::active()));
            // A stalled event loop: uninterruptible, or ext-phasync's preemption would let the
            // worker's other coroutines run between iterations
            case '/busy':
                (#[\phasync\Uninterruptible] static function () use ($query) {
                    $until = \microtime(true) + (float) $query['s'];
                    while (\microtime(true) < $until) {
                    }
                })();

                return fx_response(200, [], 'done');
            default:
                return fx_response(404, ['Content-Type' => 'text/plain'], 'Not found: ' . fx_uri($r));
        }
    };

    while (true) {
        Swerve::accept(null, $handle);
    }
})();
