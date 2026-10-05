<?php

/*
 * The application the tests serve, returned the way a project's swerve.php returns it.
 */

use Swerve\ClientRequest;
use Swerve\RequestHandler;
use Swerve\Swerve;

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


/*
 * Answer with a body of known size, as the application servers' responses did: the head carries
 * its content-length.
 */
function fx_send(ClientRequest $r, int $status, array $headers, string $body): void
{
    $names = \array_change_key_case($headers);
    if (!isset($names['content-length']) && !isset($names['transfer-encoding'])) {
        $headers['Content-Length'] = (string) \strlen($body);
    }
    $r->sendResponseHeaders($status, $headers);
    $r->write($body);
}

function fx_json(ClientRequest $r, mixed $value): void
{
    fx_send($r, 200, [], \json_encode($value));
}

/** The whole request body. */
function fx_body(ClientRequest $r): string
{
    $body = '';
    while ('' !== ($piece = $r->read())) {
        $body .= $piece;
    }

    return $body;
}

/** The request URI: the scheme and Host, and the target's path and query (an absolute-form target without its authority). */
function fx_uri(ClientRequest $r): string
{
    $target = \preg_replace('~^[a-z][a-z0-9+.-]*://[^/?#]*~i', '', $r->getTarget());
    $host   = $r->getRequestHeaders()['host'][0] ?? null;

    return null === $host ? $target : $r->getScheme() . '://' . $host . ('' === $target ? '/' : $target);
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

/*
 * A minimal WebSocket server (RFC 6455) on the raw connection of a ClientRequest, for the tests
 * of upgraded connections: the handshake, and frames in and out.
 */
function fx_ws_accept(ClientRequest $r): bool
{
    $headers = $r->getRequestHeaders();
    $key     = $headers['sec-websocket-key'][0] ?? '';
    if ('websocket' !== \strtolower($headers['upgrade'][0] ?? '') || 16 !== \strlen((string) \base64_decode($key, true))) {
        fx_send($r, 400, [], 'not a WebSocket handshake');

        return false;
    }
    $r->sendResponseHeaders(101, [
        'Upgrade'              => 'websocket',
        'Connection'           => 'Upgrade',
        'Sec-WebSocket-Accept' => \base64_encode(\sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)),
    ]);

    return true;
}

function fx_ws_frame(int $opcode, string $payload): string
{
    $n = \strlen($payload);

    return \chr(0x80 | $opcode) . ($n < 126 ? \chr($n) : ($n < 65536 ? \chr(126) . \pack('n', $n) : \chr(127) . \pack('J', $n))) . $payload;
}

/**
 * The next frame the client sent: [opcode, payload], or null when its side ended. A reset
 * throws.
 *
 * @return array{int, string}|null
 */
function fx_ws_read(ClientRequest $r, string &$buffer): ?array
{
    $need = static function (int $n) use ($r, &$buffer): ?string {
        while (\strlen($buffer) < $n) {
            $data = $r->read();
            if ('' === $data) {
                return null;
            }
            $buffer .= $data;
        }
        $bytes  = \substr($buffer, 0, $n);
        $buffer = \substr($buffer, $n);

        return $bytes;
    };
    if (null === $head = $need(2)) {
        return null;
    }
    $opcode = \ord($head[0]) & 0x0F;
    $length = \ord($head[1]) & 0x7F;
    if (126 === $length) {
        $length = \unpack('n', $need(2))[1];
    } elseif (127 === $length) {
        $length = \unpack('J', $need(8))[1];
    }
    $mask    = $need(4);
    $payload = $length > 0 ? $need($length) : '';
    $payload ^= \substr(\str_repeat($mask, \intdiv($length, 4) + 1), 0, $length);

    return [$opcode, $payload];
}

/**
 * Text and binary frames are echoed, a ping gets its pong, a close is answered with the same code.
 * When the client's side ends, it says goodbye with 1001; a reset just ends it. With $bye, it sends
 * its own close (1000) first, then waits for the client's.
 */
function fx_ws_echo(ClientRequest $r, bool $bye): void
{
    $buffer = '';
    try {
        if ($bye) {
            $r->write(fx_ws_frame(8, \pack('n', 1000)));
        }
        while (true) {
            if (null === $frame = fx_ws_read($r, $buffer)) {
                $bye || $r->write(fx_ws_frame(8, \pack('n', 1001)));

                return;
            }
            [$opcode, $payload] = $frame;
            if (8 === $opcode) {
                $bye || $r->write(fx_ws_frame(8, \substr($payload, 0, 2)));

                return;
            }
            $bye || $r->write(fx_ws_frame(9 === $opcode ? 10 : $opcode, $payload));
        }
    } catch (\phasync\IOException) {
        // The client reset the connection: nobody to say goodbye to
    }
}

return new RequestHandler((new class($version) {
    /** @var string[] what /leak keeps */
    private array $leaked = [];

    /** @var \Swerve\Claim[] what /claim keeps, by name, so that a claim outlives its request */
    private array $claims = [];

    /** /sse-beat producers still running. */
    private int $sseLive = 0;

    /** /websocket-news connections still subscribed, see /news-live. */
    private int $newsLive = 0;

    /** @var list<\WeakReference> the objects /shutdown-plain's callbacks hold, see /shutdown-probes */
    private array $probes = [];

    public function __construct(private string $version)
    {
    }

    public function handle(ClientRequest $r): void
    {
        $target = $r->getTarget();
        // Not parse_url(): it reads "//host/x" as an authority
        $origin = \preg_replace('~^[a-z][a-z0-9+.-]*://[^/?#]*~i', '', $target);
        $path   = \strstr($origin . '?', '?', true);
        \parse_str(\substr((string) \strstr($origin, '?'), 1), $query);

        switch ($path) {
            case '/hello':
                fx_send($r, 200, ['Content-Type' => 'text/plain'], 'Hello');

                return;
            case '/echo':
                fx_send($r, 200, ['Content-Type' => 'text/plain'], fx_body($r));

                return;
            case '/big':
                fx_send($r, 200, ['Content-Type' => 'text/plain'], \str_repeat('x', (int) $query['n']));

                return;
            // A static kept per request, as an adapter keeps its framework's: the request runs in
            // a switch-aware context. ?v= set, read back after waiting ?ms=
            case '/swap':
                phasync::withContext(static function () use ($r, $query) {
                    SwapFixture::$value = $query['v'];
                    phasync::sleep((int) $query['ms'] / 1000);
                    fx_send($r, 200, [], (string) SwapFixture::$value);
                }, new class implements phasync\Context\SwitchAwareInterface {
                    private ?string $own = null;

                    public function resume(): void
                    {
                        SwapFixture::$value = $this->own;
                    }

                    public function suspend(): void
                    {
                        $this->own = SwapFixture::$value;
                    }
                });

                return;
            // A WebSocket that sends what is published to 'news'
            case '/websocket-news':
                if (!fx_ws_accept($r)) {
                    return;
                }
                ++$this->newsLive;
                try {
                    foreach (Swerve::subscribe('news') as $message) {
                        $r->write(fx_ws_frame(1, $message));
                    }
                } finally {
                    --$this->newsLive;
                }

                return;
            // A WebSocket that tells its worker's pid, echoes, and says goodbye (1001) when the worker shuts down; ?throw=1: a callback that throws is registered first
            case '/websocket-shutdown':
                if (!fx_ws_accept($r)) {
                    return;
                }
                isset($query['throw']) && Swerve::onShutdown(static fn () => throw new RuntimeException('the shutdown callback failed'));
                Swerve::onShutdown(static function () use ($r) {
                    Swerve::log()->notice('onShutdown callback ran');
                    $r->write(fx_ws_frame(8, \pack('n', 1001)));
                    $r->end();
                });
                $r->write(fx_ws_frame(1, (string) \getmypid()));
                $buffer = '';
                try {
                    while (null !== $frame = fx_ws_read($r, $buffer)) {
                        if (8 === $frame[0]) {
                            $r->write(fx_ws_frame(8, \substr($frame[1], 0, 2)));

                            return;
                        }
                        $r->write(fx_ws_frame($frame[0], $frame[1]));
                    }
                } catch (\phasync\IOException) {
                }

                return;
            case '/ws':
                fx_ws_accept($r) && fx_ws_echo($r, !empty($query['bye']));

                return;
            case '/news-live':
                fx_json($r, [\getmypid(), $this->newsLive]);

                return;
            // A request that ends having registered a callback, which must then never run: it holds an object (see /shutdown-probes)
            case '/shutdown-plain':
                $probe          = new \stdClass();
                $this->probes[] = \WeakReference::create($probe);
                Swerve::onShutdown(static function () use ($probe) {
                    Swerve::log()->notice('onShutdown callback of a request that ended ran');
                });
                fx_send($r, 200, [], 'registered');

                return;
            // How many of /shutdown-plain's objects are still alive, after a garbage collection
            case '/shutdown-probes':
                \phasync::sleep(0.6); // the event loop collects cycles half a second after a coroutine ends
                \gc_collect_cycles();
                fx_json($r, \count(\array_filter($this->probes, static fn (\WeakReference $w) => null !== $w->get())));

                return;
            // Swerve::cache(): ?k= and ?v= (JSON), ?ttl= seconds; answers with the worker's pid
            case '/cache-set':
                fx_json($r, [\getmypid(), Swerve::cache()->set($query['k'], \json_decode($query['v'], true), isset($query['ttl']) ? (int) $query['ttl'] : null)]);

                return;
            case '/cache-get':
                fx_json($r, [\getmypid(), Swerve::cache()->get($query['k'], 'missing')]);

                return;
            case '/cache-del':
                fx_json($r, [\getmypid(), Swerve::cache()->delete($query['k'])]);

                return;
            case '/cache-clear':
                fx_json($r, [\getmypid(), Swerve::cache()->clear()]);

                return;
            // Swerve::claim(): ?n= name, ?timeout= seconds to wait; answers [pid, acquired, elapsed].
            // A handle that acquired is kept, so that it outlives its request; /drop lets it go
            case '/claim':
                $start    = \microtime(true);
                $claim    = Swerve::claim($query['n']);
                $acquired = null !== $claim->acquire((float) ($query['timeout'] ?? 0));
                if ($acquired) {
                    $this->claims[$query['n']] = $claim;
                }
                fx_json($r, [\getmypid(), $acquired, \microtime(true) - $start]);

                return;
            case '/available':
                fx_json($r, [\getmypid(), Swerve::claim($query['n'])->available()]);

                return;
            case '/held':
                fx_json($r, [\getmypid(), isset($this->claims[$query['n']]) && $this->claims[$query['n']]->held()]);

                return;
            case '/release':
                $claim = $this->claims[$query['n']] ?? null;
                $claim?->release();
                unset($this->claims[$query['n']]);
                fx_json($r, [\getmypid(), null !== $claim]);

                return;
            case '/drop':
                unset($this->claims[$query['n']]); // the destructor releases
                fx_json($r, [\getmypid(), true]);

                return;
            // A critical section guarded by claim ?n=, for the mutual exclusion tests: waits ?timeout=
            // seconds, appends "enter <seq> <pid>", sleeps ?ms=, appends "leave <seq>" to
            // $SWERVE_TEST_DIR/crit.log, one write each, and releases (?drop=1: by letting the handle go).
            // Answers [pid, 'done' or 'timeout', seq].
            case '/crit':
                $section = static function () use ($query): string {
                    $claim = Swerve::claim($query['n']);
                    if (null === $claim->acquire((float) $query['timeout'])) {
                        return 'timeout';
                    }
                    $log = \fopen(\getenv('SWERVE_TEST_DIR') . '/crit.log', 'a');
                    \fwrite($log, "enter {$query['seq']} " . \getmypid() . "\n");
                    phasync::sleep((float) $query['ms'] / 1000);
                    \fwrite($log, "leave {$query['seq']}\n");
                    \fclose($log);
                    if (!isset($query['drop'])) {
                        $claim->release();
                    }

                    return 'done';
                };
                fx_json($r, [\getmypid(), $section(), (int) $query['seq']]);

                return;
            // Acquires ?n=, then fails: the exception unwinds past the handle
            case '/claim-throw':
                $claim = Swerve::claim($query['n']);
                $claim->acquire();
                throw new RuntimeException('claim-boom');
            // Acquires ?n= in a coroutine that is cancelled while it sleeps holding the claim
            case '/claim-cancel':
                $co = phasync::go(static function () use ($query) {
                    $claim = Swerve::claim($query['n']);
                    $claim->acquire();
                    phasync::sleep(30);
                });
                phasync::sleep(0.1);
                phasync::cancel($co);
                try {
                    phasync::await($co); // it unwinds, and its handle goes, before this answers
                } catch (\Throwable) {
                }
                fx_json($r, [\getmypid(), true]);

                return;
            // Acquires ?n=, holds it ?ms= milliseconds inside this request, releases by the handle going
            case '/claim-hold':
                $claim = Swerve::claim($query['n']);
                $got   = null !== $claim->acquire();
                phasync::sleep((float) $query['ms'] / 1000);
                fx_json($r, [\getmypid(), $got]);

                return;
            // The claims directory, to see that it is the master's and is removed
            case '/claim-dir':
                fx_json($r, [\getmypid(), \Swerve\Claim::directory()]);

                return;
            // The claim named by the request body, for names that do not fit a query: ?op= claim|available|release
            case '/claim-raw':
                $name = fx_body($r);
                $pid  = \getmypid();
                if ('available' === $query['op']) {
                    fx_json($r, [$pid, Swerve::claim($name)->available()]);
                } elseif ('release' === $query['op']) {
                    $claim = $this->claims[$name] ?? null;
                    $claim?->release();
                    unset($this->claims[$name]);
                    fx_json($r, [$pid, null !== $claim]);
                } else {
                    $claim = Swerve::claim($name);
                    $got   = null !== $claim->acquire();
                    $got && $this->claims[$name] = $claim;
                    fx_json($r, [$pid, $got]);
                }

                return;
            // Many lookups at once from one request's coroutines: each gets its own answer
            case '/cache-many':
                $cache = Swerve::cache();
                $cache->setMultiple(\array_combine(\array_map(fn ($i) => "many$i", \range(1, 50)), \range(1, 50)));
                $coroutines = \array_map(fn ($i) => phasync::go(fn () => $cache->get("many$i")), \range(1, 50));
                fx_json($r, \array_map(phasync::await(...), $coroutines));

                return;
            // Work after the response: writes "done" to $SWERVE_TEST_DIR/after-response ?ms= later
            case '/after-response':
                phasync::go(static function () use ($query) {
                    phasync::sleep((int) $query['ms'] / 1000);
                    \file_put_contents(\getenv('SWERVE_TEST_DIR') . '/after-response', 'done');
                });
                fx_send($r, 200, [], 'ok');

                return;
            case '/sleep':
                phasync::sleep(((int) $query['ms']) / 1000);
                fx_send($r, 200, ['Content-Type' => 'text/plain'], 'slept ' . $query['id']);

                return;
            case '/params':
                $cookies = [];
                foreach (\explode(';', \implode(';', $r->getRequestHeaders()['cookie'] ?? [])) as $pair) {
                    if (2 === \count($kv = \explode('=', \trim($pair), 2)) && !isset($cookies[$kv[0]])) {
                        $cookies[$kv[0]] = \urldecode($kv[1]);
                    }
                }
                fx_send($r, 200, ['Content-Type' => 'application/json'], \json_encode([
                    'method'  => $r->getMethod(),
                    'target'  => $target,
                    'uri'     => fx_uri($r),
                    'host'    => $r->getRequestHeaders()['host'][0] ?? '',
                    'cookies' => $cookies,
                    'headers' => \array_map(static fn (array $v) => \implode(', ', $v), $r->getRequestHeaders()),
                ]));

                return;
            // The largest piece one read(65536) of the request body returned
            case '/read-sizes':
                $max = 0;
                while ('' !== ($piece = $r->read(65536))) {
                    $max = \max($max, \strlen($piece));
                }
                fx_send($r, 200, [], (string) $max);

                return;
            // A body over a non-blocking socket that another coroutine of this worker writes
            // to later (after ?ms=, 200 by default): its read() returns '' until then. ?cl= also
            // declares its length.
            case '/nonblocking':
                [$a, $b] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
                \stream_set_blocking($a, false);
                phasync::go(static function () use ($b, $query) {
                    phasync::sleep(((int) ($query['ms'] ?? 200)) / 1000);
                    \fwrite($b, 'late data');
                    \fclose($b);
                });
                $r->sendResponseHeaders(200, isset($query['cl']) ? ['Content-Length' => $query['cl']] : []);
                while (true) {
                    $data = \fread($a, 65536);
                    if (false !== $data && '' !== $data) {
                        $r->write($data);
                    } elseif (\feof($a)) {
                        break;
                    } else {
                        phasync::readable($a);
                    }
                }
                \fclose($a);

                return;
            // Streams whose size is 0 although they have data
            case '/pipe':
                $r->sendFile(\popen('echo from-pipe', 'r'));

                return;
            case '/proc':
                $r->sendFile(\fopen('/proc/self/stat', 'r'));

                return;
            // phasync::finally() in the handler: runs after the whole (streamed) response, before the
            // next request on the connection
            case '/finally-stream':
                $log   = &fixture_log();
                $fiber = Fiber::getCurrent();
                phasync::finally(static function () use (&$log, $fiber) {
                    phasync::sleep(0.01); // it may wait
                    $log[] = 'finally ran in the ' . (Fiber::getCurrent() === $fiber ? "request's coroutine" : 'another coroutine');
                });
                $r->sendResponseHeaders(200);
                foreach (['a', 'b', 'c'] as $chunk) {
                    phasync::sleep(0.02);
                    $r->write($chunk);
                }
                $log[] = 'body ended';
                $r->end();

                return;
            case '/finally-log':
                $log  = &fixture_log();
                $json = \json_encode($log);
                $log  = [];
                fx_send($r, 200, ['Content-Type' => 'application/json'], $json);

                return;
            // ?n= pieces of 10 bytes ("piece 000\n", ...), ?ms= apart; ?size=1 declares the length, ?throw=1 fails at the third
            case '/stream':
                $n  = (int) $query['n'];
                $ms = (int) ($query['ms'] ?? 0);
                $r->sendResponseHeaders(200, !empty($query['size']) ? ['Content-Length' => (string) (10 * $n)] : []);
                for ($i = 0; $i < $n; ++$i) {
                    if ($i > 0) {
                        phasync::sleep($ms / 1000);
                    }
                    if (!empty($query['throw']) && 2 === $i) {
                        throw new RuntimeException('failed while streaming');
                    }
                    $r->write(\sprintf("piece %03d\n", $i));
                }

                return;
            // Declares ?cl= bytes, and sends ?actual= pieces of 10
            case '/applength':
                $r->sendResponseHeaders(200, ['Content-Length' => $query['cl']]);
                for ($i = 0; $i < (int) $query['actual']; ++$i) {
                    $r->write(\sprintf("piece %03d\n", $i));
                }

                return;
            case '/echo-stream':
                $r->sendResponseHeaders(200, isset($r->getRequestHeaders()['content-length']) ? ['Content-Length' => $r->getRequestHeaders()['content-length'][0]] : []);
                while ('' !== ($piece = $r->read())) {
                    $r->write($piece);
                }

                return;
            case '/first':
                $data = '';
                while (\strlen($data) < (int) $query['n']) {
                    $data .= $r->read((int) $query['n'] - \strlen($data));
                }
                fx_send($r, 200, [], $data);

                return;
            // "prefix\n" first, then the request body: it is only read after the head was sent
            case '/lazy-echo':
                $r->sendResponseHeaders(200);
                $r->write("prefix\n");
                while ('' !== ($piece = $r->read())) {
                    $r->write($piece);
                }

                return;
            // The request body, echoed after its first 3 bytes were read
            case '/partial':
                $r->read(3);
                $r->sendResponseHeaders(200, ['Content-Length' => (string) ((int) $r->getRequestHeaders()['content-length'][0] - 3)]);
                while ('' !== ($piece = $r->read())) {
                    $r->write($piece);
                }

                return;
            case '/status':
                $code = (int) $query['code'];
                if (\in_array($code, [204, 205, 304], true)) {
                    $r->sendResponseHeaders($code);
                } else {
                    fx_send($r, $code, [], 'x');
                }

                return;
            case '/throw':
                throw new RuntimeException('boom');
            // Faults for the supervision tests
            case '/pid':
                fx_send($r, 200, [], (string) \getmypid());

                return;
            case '/version':
                fx_send($r, 200, [], $this->version);

                return;
            case '/exit':
                exit((int) ($query['code'] ?? 3));
            // With ?small=1, in many small pieces, which makes PHP's shutdown after it slow
            case '/oom':
                for ($i = 0; isset($query['small']); ++$i) {
                    $this->leaked[] = "x$i";
                }
                $s = 'x';
                while (true) {
                    $s .= $s;
                }
            // Keeps ?kb= KiB for good, after sleeping ?ms=: a steady leak
            case '/leak':
                phasync::sleep(((int) ($query['ms'] ?? 0)) / 1000);
                $this->leaked[] = \str_repeat('x', (int) $query['kb'] * 1024);
                fx_send($r, 200, [], (string) \memory_get_usage(true));

                return;
            // Uninterruptible: with phasync-ext, checkpoint preemption would let other
            // coroutines (the heartbeat among them) run between iterations, healing the
            // very stall this route exists to simulate for the watchdog tests.
            case '/spin':
                (#[\phasync\Uninterruptible] static function () {
                    while (true) {
                    }
                })();

                return;
            // A blocking sleep of ?ms=, answering how long it took: a signal would end it early
            case '/usleep':
                $start = \microtime(true);
                \usleep((int) $query['ms'] * 1000);
                fx_send($r, 200, [], \sprintf('%.2f', \microtime(true) - $start));

                return;
            // A background job that outlives the request (and the worker), for ?s= seconds
            case '/spawn':
                \exec('sleep ' . (int) ($query['s'] ?? 30) . ' > /dev/null 2>&1 &');
                fx_send($r, 200, [], 'spawned');

                return;
            // A background coroutine that fails after the response was sent
            case '/bgthrow':
                phasync::go(static function () {
                    phasync::sleep(0.2);
                    throw new RuntimeException('background boom');
                });
                fx_send($r, 200, [], 'ok');

                return;
            // Headers that are not valid: ?kind= value, name, token (?name=)
            case '/bad':
                $r->sendResponseHeaders(200, match ($query['kind']) {
                    'value' => ['X-A' => ["a\r\nX-Evil: 1"]],
                    'name'  => ["X-Evil: 1\r\nX-B" => ['v']],
                    'token' => [$query['name'] => ['x']],
                });

                return;
            // An upgrade refused: 426 names the protocol it requires (RFC 9110 15.5.22)
            case '/refuse-upgrade':
                fx_send($r, 426, ['Upgrade' => 'websocket', 'Connection' => 'Upgrade', 'Sec-WebSocket-Version' => '13'], 'upgrade required');

                return;
            // The body upper-cased as it arrives, while it arrives
            case '/duplex':
                $r->sendResponseHeaders(200);
                while ('' !== ($piece = $r->read(8192))) {
                    $r->write(\strtoupper($piece));
                }

                return;
            // The purest tunnel: what the client sends comes back
            case '/upgrade-echo':
                $r->sendResponseHeaders(101, ['Upgrade' => 'echo', 'Connection' => 'Upgrade']);
                while ('' !== ($piece = $r->read())) {
                    $r->write($piece);
                }

                return;
            // A tunnel sending ?kb= KiB and "END", whose input is never read; with ?read=1, read to its end afterwards
            case '/upgrade-hold':
                $r->sendResponseHeaders(101, ['Upgrade' => 'x', 'Connection' => 'Upgrade']);
                $r->write(\str_repeat('y', 1024 * (int) $query['kb']) . 'END');
                $r->end();
                if (!empty($query['read'])) {
                    while ('' !== $r->read(65536)) {
                    }
                }

                return;
            // A tunnel answering each piece upper-cased; "q" says bye and ends it, the end of
            // the client's side says EOF and ends it, unless ?ignore-eof=1. ?wait= ms before the 101.
            case '/upgrade-upper':
                phasync::sleep((int) ($query['wait'] ?? 0) / 1000);
                $r->sendResponseHeaders(101, ['Upgrade' => 'upper', 'Connection' => 'Upgrade']);
                while ('' !== ($piece = $r->read(65536))) {
                    if (\str_ends_with($piece, 'q')) {
                        $r->write(\strtoupper(\substr($piece, 0, -1)) . "bye\n");
                        $r->end();

                        return;
                    }
                    $r->write(\strtoupper($piece));
                }
                if (empty($query['ignore-eof'])) {
                    $r->write("EOF\n");
                    $r->end();

                    return;
                }
                while (true) {
                    phasync::sleep(1);
                }
            // A tunnel sending ?mb= MiB, which the application gives up ?ms= after the 101 (a
            // client that no longer answers its pings): it closes the connection
            case '/upgrade-abort':
                $r->sendResponseHeaders(101, ['Upgrade' => 'x', 'Connection' => 'Upgrade']);
                phasync::go(static function () use ($r, $query) {
                    phasync::sleep((int) $query['ms'] / 1000);
                    $r->close();
                });
                try {
                    $piece = \str_repeat('x', 65536);
                    for ($i = 16 * (int) $query['mb']; $i > 0; --$i) {
                        $r->write($piece);
                    }
                } catch (\phasync\IOException) {
                }

                return;
            // Server-Sent Events: ?n= events, ?ms= apart
            case '/sse':
                $r->sendResponseHeaders(200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache']);
                for ($i = 0; $i < (int) $query['n']; ++$i) {
                    if ($i > 0) {
                        phasync::sleep((int) $query['ms'] / 1000);
                    }
                    $r->write("data: $i\n\n");
                }

                return;
            // Server-Sent Events that learn their client left: a heartbeat every 100 ms, until a
            // write throws. /sse-live counts the producers still running.
            case '/sse-beat':
                $r->sendResponseHeaders(200, ['Content-Type' => 'text/event-stream']);
                ++$this->sseLive;
                try {
                    while (true) {
                        $r->write(": beat\n\n");
                        phasync::sleep(0.1);
                    }
                } catch (\phasync\IOException) {
                } finally {
                    --$this->sseLive;
                }

                return;
            case '/sse-live':
                fx_send($r, 200, [], (string) $this->sseLive);

                return;
            // Swerve::subscribe() (?ordered=1: an OrderedChannel) as Server-Sent Events: "ready <pid>"
            // once subscribed, then ?n= messages of ?topic=; ?maxlag= seconds, ?stall= seconds before
            // the first is read; "lag" if the subscription throws SubscriberLagException
            case '/subscribe':
                $maxLag       = (float) ($query['maxlag'] ?? 30);
                $subscription = isset($query['ordered']) ? (new \Swerve\OrderedChannel($query['topic']))->subscribe($maxLag) : Swerve::subscribe($query['topic'], $maxLag);
                $r->sendResponseHeaders(200, ['Content-Type' => 'text/event-stream']);
                $r->write('data: ready ' . \getmypid() . "\n\n");
                $r->flush();
                $i = 0;
                if (isset($query['stall'])) {
                    phasync::sleep((float) $query['stall']);
                }
                try {
                    foreach ($subscription as $message) {
                        $r->write('data: ' . (\is_string($message) ? $message : 'json ' . \json_encode($message)) . "\n\n");
                        if (++$i >= (int) $query['n']) {
                            break;
                        }
                    }
                } catch (\Swerve\SubscriberLagException) {
                    $r->write("data: lag\n\n");
                }

                return;
            // A structured message, sent as JSON: ['m' => ?m, 'n' => 1, 'list' => [1, 2]]
            case '/publish-json':
                Swerve::publish($query['topic'], ['m' => $query['m'], 'n' => 1, 'list' => [1, 2]]);
                fx_send($r, 200, [], 'published');

                return;
            // ?count= messages "<?id>:<i>:" and ?size= (0) more bytes to ?topic=, ?sleep= ms (0.5) apart, with
            // an OrderedChannel for ?ordered=1; answers the pid
            case '/publish-seq':
                for ($i = 0; $i < (int) $query['count']; ++$i) {
                    $message = $query['id'] . ':' . $i . ':' . \str_repeat((string) ($i % 10), (int) ($query['size'] ?? 0));
                    isset($query['ordered']) ? (new \Swerve\OrderedChannel($query['topic']))->write($message) : Swerve::publish($query['topic'], $message);
                    phasync::sleep((float) ($query['sleep'] ?? 0.5) / 1000);
                }
                fx_send($r, 200, [], (string) \getmypid());

                return;
            case '/publish-ordered':
                (new \Swerve\OrderedChannel($query['topic']))->write($query['m']);
                fx_send($r, 200, [], 'published');

                return;
            // Blocks the worker's event loop for ?ms= (a busy wait: with phasync-ext, usleep() yields)
            case '/block':
                (#[\phasync\Uninterruptible] static function () use ($query) {
                    for ($end = \microtime(true) + (int) $query['ms'] / 1000; \microtime(true) < $end;) {
                    }
                })();
                fx_send($r, 200, [], 'blocked');

                return;
            case '/publish-end':
                Swerve::publish($query['topic'], ['end' => true]);
                fx_send($r, 200, [], 'published');

                return;
            case '/publish':
                Swerve::publish($query['topic'], \str_repeat($query['m'], (int) ($query['times'] ?? 1)));
                fx_send($r, 200, [], 'published');

                return;
            case '/app-log':
                Swerve::log()->warning('from the application: {what}', ['what' => 'hi']);
                fx_send($r, 200, [], 'logged');

                return;
            case '/topics':
                fx_send($r, 200, [], \implode(',', Swerve\Util\Topics::active()));

                return;
            // A stalled event loop: uninterruptible, or phasync-ext's preemption would let the
            // worker's other coroutines run between iterations
            case '/busy':
                (#[\phasync\Uninterruptible] static function () use ($query) {
                    $until = \microtime(true) + (float) $query['s'];
                    while (\microtime(true) < $until) {
                    }
                })();
                fx_send($r, 200, [], 'done');

                return;
            default:
                fx_send($r, 404, ['Content-Type' => 'text/plain'], 'Not found: ' . fx_uri($r));
        }
    }
})->handle(...));
