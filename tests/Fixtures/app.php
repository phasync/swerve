<?php

/*
 * The application the tests serve, returned the way a project's swerve.php returns it.
 */

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;

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

/**
 * A response whose getHeaders() returns $headers as they are, bypassing Nyholm's validation.
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

                return new Response(200, isset($query['cl']) ? ['Content-Length' => $query['cl']] : [], Nyholm\Psr7\Stream::create($a));
            })(),
            // Streams whose getSize() is 0 although they have data
            '/pipe'        => new Response(200, [], Nyholm\Psr7\Stream::create(\popen('echo from-pipe', 'r'))),
            '/proc'        => new Response(200, [], Nyholm\Psr7\Stream::create(\fopen('/proc/self/stat', 'r'))),
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
            '/spin'        => (static function () {
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
            default   => new Response(404, ['Content-Type' => 'text/plain'], 'Not found: ' . $request->getUri()),
        };
    }
};
