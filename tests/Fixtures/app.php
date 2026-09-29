<?php

/*
 * The application the tests serve, returned the way a project's swerve.php returns it.
 */

use phasync\Psr\Response;
use phasync\Psr\UnbufferedStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Http\Virtual;
use Swerve\Http\WebSocket;
use Swerve\Swerve;

/*
 * Supervision tests control how the application loads through the directory in
 * SWERVE_TEST_DIR: a file `crash` there makes every load fail (exit 3), `load-ms` makes it
 * take that long, and `version.php` returns what /version answers; a parse error in it makes
 * the load fail.
 */
$testDir = \getenv('SWERVE_TEST_DIR') ?: null;
if ($testDir && \file_exists("$testDir/crash")) {
    exit(3);
}
if ($testDir && \file_exists("$testDir/load-ms")) {
    \usleep((int) \file_get_contents("$testDir/load-ms") * 1000);
}
$version = $testDir && \file_exists("$testDir/version.php") ? require "$testDir/version.php" : 'none';

/**
 * A non-seekable body of $n pieces of 10 bytes ("piece 000\n", ...), sleeping $ms before every
 * piece but the first, as an application streaming from a slow source. It can't be read whole:
 * getContents() and __toString() throw, so any buffering of it fails the test.
 */
function fixture_stream(int $n, int $ms, bool $sized, bool $throw): StreamInterface
{
    return new class($n, $ms, $sized, $throw) implements StreamInterface {
        private int $i = 0;

        public function __construct(private int $n, private int $ms, private bool $sized, private bool $throw)
        {
        }

        public function read(int $length): string
        {
            if ($this->i >= $this->n) {
                return '';
            }
            if ($this->i > 0) {
                phasync::sleep($this->ms / 1000);
            }
            if ($this->throw && 2 === $this->i) {
                throw new RuntimeException('failed while streaming');
            }

            return sprintf("piece %03d\n", $this->i++);
        }

        public function eof(): bool
        {
            return $this->i >= $this->n;
        }

        public function getSize(): ?int
        {
            return $this->sized ? 10 * $this->n : null;
        }

        public function getContents(): string
        {
            throw new LogicException('buffered');
        }

        public function __toString(): string
        {
            throw new LogicException('buffered');
        }

        public function close(): void
        {
        }

        public function detach()
        {
            return null;
        }

        public function tell(): int
        {
            return 10 * $this->i;
        }

        public function isSeekable(): bool
        {
            return false;
        }

        public function seek(int $offset, int $whence = SEEK_SET): void
        {
            throw new RuntimeException('not seekable');
        }

        public function rewind(): void
        {
            throw new RuntimeException('not seekable');
        }

        public function isWritable(): bool
        {
            return false;
        }

        public function write(string $string): int
        {
            throw new RuntimeException('not writable');
        }

        public function isReadable(): bool
        {
            return true;
        }

        public function getMetadata(?string $key = null)
        {
            return null === $key ? [] : null;
        }
    };
}

/**
 * A non-seekable body of unknown size whose read($length) and eof() are the given functions.
 */
function fixture_callback_stream(Closure $read, Closure $eof): StreamInterface
{
    return new class($read, $eof) implements StreamInterface {
        public function __construct(private Closure $read, private Closure $eof)
        {
        }

        public function read(int $length): string
        {
            return ($this->read)($length);
        }

        public function eof(): bool
        {
            return ($this->eof)();
        }

        public function getSize(): ?int
        {
            return null;
        }

        public function getContents(): string
        {
            throw new LogicException('buffered');
        }

        public function __toString(): string
        {
            throw new LogicException('buffered');
        }

        public function close(): void
        {
        }

        public function detach()
        {
            return null;
        }

        public function tell(): int
        {
            throw new RuntimeException('no position');
        }

        public function isSeekable(): bool
        {
            return false;
        }

        public function seek(int $offset, int $whence = SEEK_SET): void
        {
            throw new RuntimeException('not seekable');
        }

        public function rewind(): void
        {
            throw new RuntimeException('not seekable');
        }

        public function isWritable(): bool
        {
            return false;
        }

        public function write(string $string): int
        {
            throw new RuntimeException('not writable');
        }

        public function isReadable(): bool
        {
            return true;
        }

        public function getMetadata(?string $key = null)
        {
            return null === $key ? [] : null;
        }
    };
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

/**
 * A minimal WebSocket server (RFC 6455), written against nothing but the two streams: the
 * request body is what the client sends after the handshake, the response body what goes back.
 * Text and binary frames are echoed, a ping gets its pong, a close is answered with the same
 * code. When the client's side ends (it left, or swerve drains), it says goodbye with 1001; a
 * read that fails (the client reset the connection) just ends it.
 * With $bye, it sends its own close (1000) first, then waits for the client's.
 */
function fixture_ws(ServerRequestInterface $request, bool $bye): ResponseInterface
{
    $key = $request->getHeaderLine('Sec-WebSocket-Key');
    if ('websocket' !== strtolower($request->getHeaderLine('Upgrade')) || 16 !== strlen((string) base64_decode($key, true))) {
        return new Response(400, [], 'not a WebSocket handshake');
    }
    $out   = new UnbufferedStream(65536, PHP_FLOAT_MAX);
    $frame = static function (int $opcode, string $payload): string {
        $n = strlen($payload);

        return chr(0x80 | $opcode) . ($n < 126 ? chr($n) : ($n < 65536 ? chr(126) . pack('n', $n) : chr(127) . pack('J', $n))) . $payload;
    };
    phasync::go(static function () use ($request, $out, $frame, $bye) {
        $in     = $request->getBody();
        $buffer = '';
        $need   = static function (int $n) use ($in, &$buffer): string {
            while (strlen($buffer) < $n) {
                $data = $in->read(65536);
                if ('' === $data) {
                    throw new UnderflowException('the client\'s side ended');
                }
                $buffer .= $data;
            }
            $bytes  = substr($buffer, 0, $n);
            $buffer = substr($buffer, $n);

            return $bytes;
        };
        try {
            if ($bye) {
                $out->append($frame(8, pack('n', 1000)));
            }
            while (true) {
                $head   = $need(2);
                $opcode = ord($head[0]) & 0x0F;
                $length = ord($head[1]) & 0x7F;
                if (126 === $length) {
                    $length = unpack('n', $need(2))[1];
                } elseif (127 === $length) {
                    $length = unpack('J', $need(8))[1];
                }
                $mask    = $need(4);
                $payload = $length > 0 ? $need($length) : '';
                $payload ^= substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length);
                if (8 === $opcode) {
                    if (!$bye) {
                        $out->append($frame(8, substr($payload, 0, 2)));
                    }

                    return;
                }
                if (!$bye) {
                    $out->append(9 === $opcode ? $frame(10, $payload) : $frame($opcode, $payload));
                }
            }
        } catch (UnderflowException) {
            if (!$bye) {
                $out->append($frame(8, pack('n', 1001))); // going away
            }
        } catch (RuntimeException) {
            // The client reset the connection (PSR-7's read() error): nobody to say goodbye to
        } finally {
            $out->end();
        }
    });

    return new Response(101, [
        'Upgrade'              => 'websocket',
        'Connection'           => 'Upgrade',
        'Sec-WebSocket-Accept' => base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)),
    ], $out);
}

/**
 * A response whose getHeaders() returns $headers as they are, bypassing header normalization.
 */
function fixture_raw_headers(array $headers): ResponseInterface
{
    $response      = new class(200, [], 'x') extends Response {
        public array $raw = [];

        public function getHeaders(): array
        {
            return $this->raw;
        }
    };
    $response->raw = $headers;

    return $response;
}

return new class($version) implements RequestHandlerInterface {
    /** How often a /stalled body was read, see /stalled-reads. */
    private int $stalledReads = 0;

    /** @var string[] what /leak keeps */
    private array $leaked = [];

    /** What a background reader of /late-read or /late-probe found, see /late-result. */
    private string $late = '';

    /** @var StreamInterface[] request bodies /hold keeps, unread */
    private array $held = [];

    /** /sse-beat producers still running. */
    private int $sseLive = 0;

    /** WebSocket callbacks of /websocket-news still running, see /news-live. */
    private int $newsLive = 0;

    public function __construct(private string $version)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        \parse_str($request->getUri()->getQuery(), $query);

        return match ($request->getUri()->getPath()) {
            '/hello'  => new Response(200, ['Content-Type' => 'text/plain'], 'Hello'),
            '/echo'   => new Response(200, ['Content-Type' => 'text/plain'], (string) $request->getBody()),
            '/big'    => new Response(200, ['Content-Type' => 'text/plain'], \str_repeat('x', (int) $query['n'])),
            // Code written for PHP-FPM, run by Virtual (needs phasync-ext): a session counter, a header,
            // a cookie, the request body, chunks ?ms= apart; ?exit=1 exits after the first chunk
            '/virtual' => Virtual::run($request, static function () use ($query) {
                \session_save_path(\sys_get_temp_dir());
                \session_start();
                $_SESSION['n'] = ($_SESSION['n'] ?? 0) + 1;
                \http_response_code(201);
                \header('X-Virtual: yes');
                \setcookie('flavour', 'oat');
                echo 'n=', $_SESSION['n'], ' body=', \file_get_contents('php://input'), ' sid=', \session_id(), "\n";
                \flush();
                if (!empty($query['exit'])) {
                    exit(1);
                }
                \phasync::sleep((int) ($query['ms'] ?? 0) / 1000);
                echo "last\n";
            }),
            // A static kept per request, as an adapter keeps its framework's: the request runs in
            // a switch-aware context. ?v= set, read back after waiting ?ms=
            '/swap' => phasync::withContext(static function () use ($query) {
                SwapFixture::$value = $query['v'];
                phasync::sleep((int) $query['ms'] / 1000);

                return new Response(200, [], (string) SwapFixture::$value);
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
            }),
            // The request's own $_SESSION: ?v= written, read back after waiting ?ms=; without ?v=,
            // what the session holds
            '/virtual-session' => Virtual::run($request, static function () {
                \session_save_path(\sys_get_temp_dir());
                \session_start();
                if (isset($_GET['v'])) {
                    $_SESSION['v'] = $_GET['v'];
                    \phasync::sleep((int) ($_GET['ms'] ?? 0) / 1000);
                    $_SESSION['after'] = $_GET['v'];
                }
                echo \session_id(), ' ', ($_SESSION['v'] ?? '-'), ' ', ($_SESSION['after'] ?? '-');
            }),
            // The request's own superglobals, read before and after waiting ?ms= (needs phasync-ext)
            '/virtual-globals' => Virtual::run($request, static function () {
                $read = static fn () => ($_GET['q'] ?? '-') . '|' . ($_COOKIE['c'] ?? '-') . '|' . ($_SERVER['HTTP_X_T'] ?? '-') . '|' . ($_POST['p'] ?? '-');
                $before = $read();
                \phasync::sleep((int) ($_GET['ms'] ?? 0) / 1000);
                echo $before, ' ', $read();
            }),
            // A WebSocket that only sends: what is published to 'news' goes to the browser
            '/websocket-news' => WebSocket::from($request, function (WebSocket $ws) {
                ++$this->newsLive;
                try {
                    foreach (Swerve::subscribe('news') as $message) {
                        $ws->send($message);
                    }
                } finally {
                    --$this->newsLive;
                }
            }),
            '/news-live' => new Response(200, [], \json_encode([\getmypid(), $this->newsLive])),
            // Swerve::cache(): ?k= and ?v= (JSON), ?ttl= seconds; answers with the worker's pid
            '/cache-set' => new Response(200, [], \json_encode([\getmypid(), Swerve::cache()->set($query['k'], \json_decode($query['v'], true), isset($query['ttl']) ? (int) $query['ttl'] : null)])),
            '/cache-get' => new Response(200, [], \json_encode([\getmypid(), Swerve::cache()->get($query['k'], 'missing')])),
            '/cache-del' => new Response(200, [], \json_encode([\getmypid(), Swerve::cache()->delete($query['k'])])),
            // Many lookups at once from one request's coroutines: each gets its own answer
            '/cache-many' => new Response(200, [], \json_encode((static function () {
                $cache = Swerve::cache();
                $cache->setMultiple(\array_combine(\array_map(fn ($i) => "many$i", \range(1, 50)), \range(1, 50)));
                $coroutines = \array_map(fn ($i) => phasync::go(fn () => $cache->get("many$i")), \range(1, 50));

                return \array_map(phasync::await(...), $coroutines);
            })())),
            // Work after the response: writes "done" to $SWERVE_TEST_DIR/after-response ?ms= later
            '/after-response' => (static function () use ($query) {
                phasync::go(static function () use ($query) {
                    phasync::sleep((int) $query['ms'] / 1000);
                    \file_put_contents(\getenv('SWERVE_TEST_DIR') . '/after-response', 'done');
                });

                return new Response(200, [], 'ok');
            })(),
            '/sleep'  => (static function () use ($query) {
                phasync::sleep(((int) $query['ms']) / 1000);

                return new Response(200, ['Content-Type' => 'text/plain'], 'slept ' . $query['id']);
            })(),
            '/params' => new Response(200, ['Content-Type' => 'application/json'], \json_encode([
                'method'  => $request->getMethod(),
                'target'  => $request->getRequestTarget(),
                'uri'     => (string) $request->getUri(),
                'host'    => $request->getHeaderLine('Host'),
                'cookies' => $request->getCookieParams(),
                'headers' => \array_change_key_case(\array_map(static fn (array $v) => \implode(', ', $v), $request->getHeaders())),
            ])),
            // The largest piece one read(65536) of the request body returned
            '/read-sizes' => (static function () use ($request) {
                $body = $request->getBody();
                $max  = 0;
                while (!$body->eof()) {
                    $max = \max($max, \strlen($body->read(65536)));
                }

                return new Response(200, [], (string) $max);
            })(),
            // A body over a non-blocking socket that another coroutine of this worker writes
            // to later (after ?ms=, 200 by default): its read() returns '' until then. ?cl= also
            // declares its length.
            '/nonblocking' => (static function () use ($query) {
                [$a, $b] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
                \stream_set_blocking($a, false);
                phasync::go(static function () use ($b, $query) {
                    phasync::sleep(((int) ($query['ms'] ?? 200)) / 1000);
                    \fwrite($b, 'late data');
                    \fclose($b);
                });

                return new Response(200, isset($query['cl']) ? ['Content-Length' => $query['cl']] : [], phasync\Psr\StreamFactory::create($a));
            })(),
            // Streams whose getSize() is 0 although they have data
            '/pipe'        => new Response(200, [], phasync\Psr\StreamFactory::create(\popen('echo from-pipe', 'r'))),
            '/proc'        => new Response(200, [], phasync\Psr\StreamFactory::create(\fopen('/proc/self/stat', 'r'))),
            // phasync::finally() in handle(): runs after the whole (streamed) response, before the
            // next request on the connection
            '/finally-stream' => (static function () {
                $log = &fixture_log();
                $out = new UnbufferedStream(65536, PHP_FLOAT_MAX);
                phasync::go(static function () use ($out, &$log) {
                    foreach (['a', 'b', 'c'] as $chunk) {
                        phasync::sleep(0.02);
                        $out->append($chunk);
                    }
                    $log[] = 'body ended';
                    $out->end();
                });
                $fiber = Fiber::getCurrent();
                phasync::finally(static function () use (&$log, $fiber) {
                    phasync::sleep(0.01); // it may wait
                    $log[] = 'finally ran in the ' . (Fiber::getCurrent() === $fiber ? "request's coroutine" : 'another coroutine');
                });

                return new Response(200, [], $out);
            })(),
            '/finally-log' => (static function () {
                $log   = &fixture_log();
                $json  = json_encode($log);
                $log   = [];

                return new Response(200, ['Content-Type' => 'application/json'], $json);
            })(),
            '/stream'      => new Response(200, [], fixture_stream((int) $query['n'], (int) ($query['ms'] ?? 0), (bool) ($query['size'] ?? 0), (bool) ($query['throw'] ?? 0))),
            '/applength'   => new Response(200, ['Content-Length' => $query['cl']], fixture_stream((int) $query['actual'], 0, false, false)),
            '/echo-stream' => new Response(200, [], $request->getBody()),
            '/first'       => (static function () use ($request, $query) {
                $body = $request->getBody();
                $data = '';
                while (\strlen($data) < (int) $query['n']) {
                    $data .= $body->read((int) $query['n'] - \strlen($data));
                }

                return new Response(200, [], $data);
            })(),
            // "prefix\n" first, then the request body: it is only read after the head was sent
            '/lazy-echo'   => (static function () use ($request) {
                $prefix = true;

                return new Response(200, [], fixture_callback_stream(static function (int $length) use ($request, &$prefix) {
                    if ($prefix) {
                        $prefix = false;

                        return "prefix\n";
                    }

                    return $request->getBody()->read($length);
                }, static function () use ($request, &$prefix) {
                    return !$prefix && $request->getBody()->eof();
                }));
            })(),
            // A body that never has anything, and never ends
            '/stalled'       => new Response(200, [], fixture_callback_stream(function () {
                ++$this->stalledReads;

                return '';
            }, static fn () => false)),
            '/stalled-reads' => new Response(200, [], (string) $this->stalledReads),
            // The request body, echoed after its first 3 bytes were read
            '/partial'       => (static function () use ($request) {
                $request->getBody()->read(3);

                return new Response(200, [], $request->getBody());
            })(),
            '/status'      => new Response((int) $query['code'], [], 'x'),
            '/throw'       => throw new RuntimeException('boom'),
            // Faults for the supervision tests
            '/pid'         => new Response(200, [], (string) \getmypid()),
            '/version'     => new Response(200, [], $this->version),
            '/exit'        => exit((int) ($query['code'] ?? 3)),
            // With ?small=1, in many small pieces, which makes PHP's shutdown after it slow
            '/oom'         => (function () use ($query) {
                for ($i = 0; isset($query['small']); ++$i) {
                    $this->leaked[] = "x$i";
                }
                $s = 'x';
                while (true) {
                    $s .= $s;
                }
            })(),
            // Keeps ?kb= KiB for good, after sleeping ?ms=: a steady leak
            '/leak'        => (function () use ($query) {
                phasync::sleep(((int) ($query['ms'] ?? 0)) / 1000);
                $this->leaked[] = \str_repeat('x', (int) $query['kb'] * 1024);

                return new Response(200, [], (string) \memory_get_usage(true));
            })(),
            // Uninterruptible: with phasync-ext, checkpoint preemption would let other
            // coroutines (the heartbeat among them) run between iterations, healing the
            // very stall this route exists to simulate for the watchdog tests.
            '/spin'        => (#[\phasync\Uninterruptible] static function () {
                while (true) {
                }
            })(),
            // Looped, because a signal ends sleep() early
            '/block'       => (static function () {
                while (true) {
                    \sleep(1000);
                }
            })(),
            // A blocking sleep of ?ms=, answering how long it took: a signal would end it early
            '/usleep'      => (static function () use ($query) {
                $start = \microtime(true);
                \usleep((int) $query['ms'] * 1000);

                return new Response(200, [], \sprintf('%.2f', \microtime(true) - $start));
            })(),
            // A background job that outlives the request (and the worker), for ?s= seconds
            '/spawn'       => (static function () use ($query) {
                \exec('sleep ' . (int) ($query['s'] ?? 30) . ' > /dev/null 2>&1 &');

                return new Response(200, [], 'spawned');
            })(),
            // A background coroutine that fails after the response was sent
            '/bgthrow'     => (static function () {
                phasync::go(static function () {
                    phasync::sleep(0.2);
                    throw new RuntimeException('background boom');
                });

                return new Response(200, [], 'ok');
            })(),
            '/bad'         => match ($query['kind']) {
                'reason' => new Response(200, [], 'x', '1.1', "OK\r\nX-Evil: 1"),
                'value'  => fixture_raw_headers(['X-A' => ["a\r\nX-Evil: 1"]]),
                'name'   => fixture_raw_headers(["X-Evil: 1\r\nX-B" => ['v']]),
                'token'  => fixture_raw_headers([$query['name'] => ['x']]),
            },
            /*
             * The request body after the response, and the two streams of upgrades
             */
            // The body is read ?ms= after the response, by another coroutine (its first ?first=
            // bytes in handle()): "md5:length", or the exception's class, answered by /late-result
            '/late-read'   => (function () use ($request, $query) {
                $this->late = 'pending';
                $first      = $request->getBody()->read((int) ($query['first'] ?? 0)); // in handle()
                phasync::go(function () use ($request, $query, $first) {
                    phasync::sleep((int) $query['ms'] / 1000);
                    try {
                        $data       = $first . $request->getBody()->getContents();
                        $this->late = md5($data) . ':' . strlen($data);
                    } catch (Throwable $e) {
                        $this->late = $e::class;
                    }
                });

                return new Response(202, [], 'accepted');
            })(),
            '/late-result' => new Response(200, [], $this->late),
            // What a first read(10) of the body gives in another coroutine, and eof() after it;
            // the response comes ?ms= later
            '/late-probe'  => (function () use ($request, $query) {
                phasync::go(function () use ($request) {
                    $body       = $request->getBody();
                    $this->late = json_encode([$body->read(10), $body->eof()]);
                });
                phasync::sleep((int) ($query['ms'] ?? 0) / 1000);

                return new Response(200, [], 'probing');
            })(),
            // The body read by another coroutine after the response, ?size= (10) bytes at a time,
            // ?ms= apart: "md5:length", or the exception's class, answered by /late-result and
            // written to SWERVE_TEST_DIR/late
            '/slow-consume' => (function () use ($request, $query) {
                $this->late = 'pending';
                phasync::go(function () use ($request, $query) {
                    $data = '';
                    try {
                        $body = $request->getBody();
                        while ('' !== ($piece = $body->read((int) ($query['size'] ?? 10)))) {
                            $data .= $piece;
                            phasync::sleep((int) $query['ms'] / 1000);
                        }
                        $this->late = md5($data) . ':' . strlen($data);
                    } catch (Throwable $e) {
                        $this->late = $e::class;
                    }
                    if ($dir = getenv('SWERVE_TEST_DIR')) {
                        file_put_contents("$dir/late", $this->late);
                    }
                });

                return new Response(202, [], 'accepted');
            })(),
            // The body read whole by a coroutine that handle() waits for, as middleware running
            // the rest of the stack in a coroutine of its own does
            '/child-read'  => phasync::await(phasync::go(static fn () => new Response(200, [], 'got:' . $request->getBody()->getContents()))),
            // An upgrade refused: 426 names the protocol it requires (RFC 9110 15.5.22)
            '/refuse-upgrade' => new Response(426, ['Upgrade' => 'websocket', 'Connection' => 'Upgrade', 'Sec-WebSocket-Version' => '13'], 'upgrade required'),
            // The body upper-cased as it arrives, while it arrives
            '/duplex'      => (static function () use ($request) {
                $out = new UnbufferedStream(65536, PHP_FLOAT_MAX);
                phasync::go(static function () use ($request, $out) {
                    try {
                        $body = $request->getBody();
                        while ('' !== ($piece = $body->read(8192))) {
                            $out->append(strtoupper($piece));
                        }
                    } finally {
                        $out->end();
                    }
                });

                return new Response(200, [], $out);
            })(),
            '/hold'        => (function () use ($request) {
                $this->held[] = $request->getBody();

                return new Response(200, [], 'holding');
            })(),
            // Reads the whole body inside handle(): for an upgrade request, that declines it
            '/read-in-handle' => new Response('101' === ($query['then'] ?? null) ? 101 : 200, ['Upgrade' => 'x', 'Connection' => 'Upgrade'], '[' . $request->getBody() . ']'),
            // The purest tunnel: what the client sends comes back
            '/upgrade-echo'   => new Response(101, ['Upgrade' => 'echo', 'Connection' => 'Upgrade'], $request->getBody()),
            // A tunnel sending ?kb= KiB and "END", whose input is held and never read; with
            // ?read=1, read to its end by a coroutine
            '/upgrade-hold'   => (function () use ($request, $query) {
                $this->held[] = $request->getBody();
                if (!empty($query['read'])) {
                    phasync::go(static function () use ($request) {
                        while ('' !== $request->getBody()->read(65536)) {
                        }
                    });
                }

                return new Response(101, ['Upgrade' => 'x', 'Connection' => 'Upgrade'], \str_repeat('y', 1024 * (int) $query['kb']) . 'END');
            })(),
            // A tunnel answering each piece upper-cased; "q" says bye and ends it, the end of
            // the client's side says EOF and ends it, unless ?ignore-eof=1. ?delay= ms before the
            // reader starts, ?wait= ms after it (it then waits for the response status).
            '/upgrade-upper'  => (static function () use ($request, $query) {
                phasync::sleep((int) ($query['delay'] ?? 0) / 1000);
                $out = new UnbufferedStream(65536, PHP_FLOAT_MAX);
                phasync::go(static function () use ($request, $out, $query) {
                    $in = $request->getBody();
                    while ('' !== ($piece = $in->read(65536))) {
                        if (str_ends_with($piece, 'q')) {
                            $out->append(strtoupper(substr($piece, 0, -1)) . "bye\n");
                            $out->end();

                            return;
                        }
                        $out->append(strtoupper($piece));
                    }
                    if (empty($query['ignore-eof'])) {
                        $out->append("EOF\n");
                        $out->end();
                    }
                });
                phasync::sleep((int) ($query['wait'] ?? 0) / 1000);

                return new Response(101, ['Upgrade' => 'upper', 'Connection' => 'Upgrade'], $out);
            })(),
            // A tunnel sending ?mb= MiB, which the application gives up ?ms= after the 101 (a
            // client that no longer answers its pings): it closes the request body, the way to
            // end the connection, and ends its response
            '/upgrade-abort'  => (static function () use ($request, $query) {
                $out = new UnbufferedStream(65536, PHP_FLOAT_MAX);
                phasync::go(static function () use ($out, $query) {
                    $piece = str_repeat('x', 65536);
                    for ($i = 16 * (int) $query['mb']; $i > 0; --$i) {
                        $out->append($piece);
                    }
                });
                phasync::go(static function () use ($request, $out, $query) {
                    phasync::sleep((int) $query['ms'] / 1000);
                    $request->getBody()->close();
                    $out->end();
                });

                return new Response(101, ['Upgrade' => 'x', 'Connection' => 'Upgrade'], $out);
            })(),
            '/ws'             => fixture_ws($request, !empty($query['bye'])),
            // swerve's WebSocket: echoes messages as they came (text or binary); "bye" ends the
            // callback (a close with 1000), "throw" makes it throw (1011)
            '/websocket'      => WebSocket::from($request, static function (WebSocket $ws) {
                foreach ($ws as $message) {
                    if ('bye' === $message) {
                        return;
                    }
                    if ('throw' === $message) {
                        throw new RuntimeException('the WebSocket callback failed');
                    }
                    $ws->isBinary() ? $ws->sendBinary($message) : $ws->send($message);
                }
            }),
            // Server-Sent Events: ?n= events, ?ms= apart
            '/sse'            => (static function () use ($query) {
                $out = new UnbufferedStream(65536, PHP_FLOAT_MAX);
                phasync::go(static function () use ($out, $query) {
                    for ($i = 0; $i < (int) $query['n']; ++$i) {
                        if ($i > 0) {
                            phasync::sleep((int) $query['ms'] / 1000);
                        }
                        $out->append("data: $i\n\n");
                    }
                    $out->end();
                });

                return new Response(200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache'], $out);
            })(),
            // Server-Sent Events that learn their client left: a heartbeat every 100 ms into a
            // 1-byte buffer with a 1 s deadlock timeout, so an append nobody reads throws.
            // /sse-live counts the producers still running.
            '/sse-beat'       => (function () {
                $out = new UnbufferedStream(1, 1.0);
                ++$this->sseLive;
                phasync::go(function () use ($out) {
                    try {
                        while (true) {
                            $out->append(": beat\n\n");
                            phasync::sleep(0.1);
                        }
                    } catch (phasync\TimeoutException) {
                    } finally {
                        --$this->sseLive;
                    }
                });

                return new Response(200, ['Content-Type' => 'text/event-stream'], $out);
            })(),
            '/sse-live'       => new Response(200, [], (string) $this->sseLive),
            // Swerve::subscribe() as Server-Sent Events: "ready <pid>" once subscribed, then ?n=
            // messages of ?topic=
            '/subscribe'      => (static function () use ($query) {
                $subscription = Swerve::subscribe($query['topic']);
                $out          = new UnbufferedStream(65536, PHP_FLOAT_MAX);
                $out->append('data: ready ' . \getmypid() . "\n\n");
                phasync::go(static function () use ($subscription, $out, $query) {
                    $i = 0;
                    foreach ($subscription as $message) {
                        $out->append('data: ' . (\is_string($message) ? $message : 'json ' . \json_encode($message)) . "\n\n");
                        if (++$i >= (int) $query['n']) {
                            break;
                        }
                    }
                    $out->end();
                });

                return new Response(200, ['Content-Type' => 'text/event-stream'], $out);
            })(),
            // A structured message, sent as JSON: ['m' => ?m, 'n' => 1, 'list' => [1, 2]]
            '/publish-json'   => (static function () use ($query) {
                Swerve::publish($query['topic'], ['m' => $query['m'], 'n' => 1, 'list' => [1, 2]]);

                return new Response(200, [], 'published');
            })(),
            '/publish'        => (static function () use ($query) {
                Swerve::publish($query['topic'], \str_repeat($query['m'], (int) ($query['times'] ?? 1)));

                return new Response(200, [], 'published');
            })(),
            '/app-log'        => (static function () {
                Swerve::log()->warning('from the application: {what}', ['what' => 'hi']);

                return new Response(200, [], 'logged');
            })(),
            '/topics'         => new Response(200, [], \implode(',', Swerve\Util\Topics::active())),
            // Stalls this worker's event loop for ?s= seconds
            '/busy'           => (static function () use ($query) {
                $until = \microtime(true) + (float) $query['s'];
                while (\microtime(true) < $until) {
                }

                return new Response(200, [], 'done');
            })(),
            default   => new Response(404, ['Content-Type' => 'text/plain'], 'Not found: ' . $request->getUri()),
        };
    }
};
