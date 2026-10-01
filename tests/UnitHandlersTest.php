<?php

/*
 * In-process tests of StaticFiles, Cache and Dispatcher: the PSR-15 handler and the methods are
 * called directly, no server is started.
 */

use phasync\Psr\Response;
use phasync\Psr\StreamFactory;
use phasync\Util\LruCache;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Swerve\Cache;
use Swerve\Dispatcher;
use Swerve\Http\ServerRequest;
use Swerve\ResponderInterface;
use Swerve\StaticFiles;
use Swerve\Util\RequestContextFactory;
use Swerve\Util\Topics;

function unit_request(string $target, array $headers = [], string $method = 'GET'): ServerRequest
{
    $lower = [];
    $names = [];
    foreach ($headers as $name => $value) {
        $lower[strtolower($name)] = [$value];
        $names[strtolower($name)] = $name;
    }

    return new ServerRequest($method, $target, StreamFactory::create(''), $lower, $names, [], '1.1');
}

/** A public directory: files of several types, a 100-byte digits file, directories, a dotfile, symlinks. */
function unit_public(): string
{
    $dir = temp_path(true);
    mkdir("$dir/public/docs/deep", 0777, true);
    mkdir("$dir/public/.well-known");
    mkdir("$dir/public/.hidden");
    mkdir("$dir/outside");
    file_put_contents("$dir/public/index.html", 'home');
    file_put_contents("$dir/public/digits.txt", implode('', array_map(static fn ($i) => $i % 10, range(0, 99))));
    file_put_contents("$dir/public/UPPER.CSS", 'a{}');
    file_put_contents("$dir/public/noext", 'x');
    file_put_contents("$dir/public/thing.unknown", 'x');
    file_put_contents("$dir/public/a b.txt", 'spaced');
    file_put_contents("$dir/public/empty.txt", '');
    file_put_contents("$dir/public/docs/index.html", 'docs');
    file_put_contents("$dir/public/docs/.secret", 'hidden');
    file_put_contents("$dir/public/.hidden/x.txt", 'hidden');
    file_put_contents("$dir/public/.well-known/a.txt", 'wk');
    file_put_contents("$dir/outside/index.html", 'outside');
    file_put_contents("$dir/public-evil.txt", 'sibling');
    symlink("$dir/outside", "$dir/public/outlink");
    symlink("$dir/public/digits.txt", "$dir/public/inlink.txt");

    return "$dir/public";
}

/** Run the middleware, with a next handler answering 404 "app"; returns the response and its body. */
function unit_static(StaticFiles $files, string $target, array $headers = [], string $method = 'GET'): array
{
    $app = new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new Response(404, [], 'app');
        }
    };

    return phasync::run(static function () use ($files, $app, $target, $headers, $method) {
        $response = $files->process(unit_request($target, $headers, $method), $app);
        $body     = (string) $response->getBody();
        $response->getBody()->close();

        return [$response, $body];
    });
}

test('a directory that does not exist is no public directory', function () {
    expect(fn () => new StaticFiles('/no/such/dir'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new StaticFiles(__FILE__))->toThrow(InvalidArgumentException::class);
});

test('the type comes from the extension, in any case; unknown or none is octet-stream', function () {
    $files = new StaticFiles(unit_public());
    $types = [];
    foreach (['/UPPER.CSS', '/noext', '/thing.unknown', '/digits.txt', '/index.html'] as $target) {
        $types[$target] = unit_static($files, $target)[0]->getHeaderLine('Content-Type');
    }
    expect($types)->toBe([
        '/UPPER.CSS'     => 'text/css; charset=utf-8',
        '/noext'         => 'application/octet-stream',
        '/thing.unknown' => 'application/octet-stream',
        '/digits.txt'    => 'text/plain; charset=utf-8',
        '/index.html'    => 'text/html; charset=utf-8',
    ]);
});

test('a served file says it accepts ranges, and a percent-encoded path names the file', function () {
    $files = new StaticFiles(unit_public());
    [$response, $body] = unit_static($files, '/a%20b.txt');
    expect([$response->getStatusCode(), $body, $response->getHeaderLine('Accept-Ranges')])->toBe([200, 'spaced', 'bytes']);
    expect(unit_static($files, '/inlink.txt')[0]->getStatusCode())->toBe(200); // a symlink within the directory
});

test('an empty file is served with a Content-Length of 0', function () {
    [$response, $body] = unit_static(new StaticFiles(unit_public()), '/empty.txt');
    expect([$response->getStatusCode(), $response->getHeaderLine('Content-Length'), $body])->toBe([200, '0', '']);
});

test('HEAD gets the headers of a GET', function () {
    $files = new StaticFiles(unit_public());
    $get   = unit_static($files, '/digits.txt')[0];
    $head  = unit_static($files, '/digits.txt', method: 'HEAD')[0];
    expect([$head->getStatusCode(), $head->getHeaderLine('Content-Length'), $head->getHeaderLine('ETag')])
        ->toBe([200, '100', $get->getHeaderLine('ETag')]);
    expect(unit_static($files, '/missing.txt', method: 'HEAD')[1])->toBe('app');
});

test('other methods and a null byte in the path go to the application', function () {
    $files = new StaticFiles(unit_public());
    foreach (['POST', 'PUT', 'DELETE', 'OPTIONS'] as $method) {
        expect(unit_static($files, '/digits.txt', method: $method)[1])->toBe('app');
    }
    expect(unit_static($files, '/digits.txt%00.png')[1])->toBe('app');
});

test('paths leaving the directory, or naming dot files, go to the application', function () {
    $files = new StaticFiles(unit_public());
    $seen  = [];
    foreach ([
        '/outlink/index.html', '/outlink/', '/../public-evil.txt', '/docs/../../public-evil.txt', '/%2e%2e/public-evil.txt',
        '/..%2fpublic-evil.txt', '/docs/.secret', '/.hidden/x.txt', '/%2ehidden/x.txt', '/.well-known/../.hidden/x.txt',
    ] as $target) {
        $seen[$target] = unit_static($files, $target)[1];
    }
    expect(array_unique($seen))->toBe(['/outlink/index.html' => 'app']);
});

test('.well-known is served, but only its own path', function () {
    $files = new StaticFiles(unit_public());
    expect(unit_static($files, '/.well-known/a.txt')[1])->toBe('wk');
    expect(unit_static($files, '/.well-known')[1])->toBe('app'); // a directory without index.html
});

test('directories: index.html at every level, the root included; without one the application answers', function () {
    $files = new StaticFiles(unit_public());
    expect(unit_static($files, '/')[1])->toBe('home');
    expect(unit_static($files, '/docs/')[1])->toBe('docs');
    expect(unit_static($files, '/docs/deep/')[1])->toBe('app');
    expect(unit_static($files, '/docs/deep')[1])->toBe('app'); // no redirect to a directory without index.html
    [$response, $body] = unit_static($files, '/docs');
    expect([$response->getStatusCode(), $response->getHeaderLine('Location'), $body])->toBe([301, '/docs/', '']);
});

test('a file named with a trailing slash is not served', function () {
    expect(unit_static(new StaticFiles(unit_public()), '/digits.txt/')[1])->toBe('app');
});

test('If-None-Match: a list, weak tags and * match; the 304 keeps the validators and has no body', function () {
    $files = new StaticFiles(unit_public());
    $etag  = unit_static($files, '/digits.txt')[0]->getHeaderLine('ETag');
    foreach (["\"a\", $etag", "W/$etag", '*', " $etag "] as $header) {
        [$response, $body] = unit_static($files, '/digits.txt', ['If-None-Match' => $header]);
        expect([$response->getStatusCode(), $body, $response->getHeaderLine('ETag')])->toBe([304, '', $etag], $header);
    }
    expect(unit_static($files, '/digits.txt', ['If-None-Match' => '"a", "b"'])[0]->getStatusCode())->toBe(200);
    expect(unit_static($files, '/digits.txt', ['If-None-Match' => $etag], 'HEAD')[0]->getStatusCode())->toBe(304);
});

test('If-None-Match wins over If-Modified-Since; an unparsable If-Modified-Since is ignored', function () {
    $files    = new StaticFiles(unit_public());
    $modified = unit_static($files, '/digits.txt')[0]->getHeaderLine('Last-Modified');
    expect(unit_static($files, '/digits.txt', ['If-None-Match' => '"nope"', 'If-Modified-Since' => $modified])[0]->getStatusCode())->toBe(200);
    expect(unit_static($files, '/digits.txt', ['If-Modified-Since' => 'not a date'])[0]->getStatusCode())->toBe(200);
    expect(unit_static($files, '/digits.txt', ['If-Modified-Since' => 'Fri, 01 Jan 2100 00:00:00 GMT'])[0]->getStatusCode())->toBe(304);
});

test('a byte range: its Content-Type and validators stay, the body is exactly the range', function () {
    $files = new StaticFiles(unit_public());
    [$response, $body] = unit_static($files, '/digits.txt', ['Range' => 'bytes=5-14']);
    expect([$response->getStatusCode(), $body, $response->getHeaderLine('Content-Range'), $response->getHeaderLine('Content-Type')])
        ->toBe([206, '5678901234', 'bytes 5-14/100', 'text/plain; charset=utf-8']);
    expect($response->getHeaderLine('ETag'))->not->toBe('');
    expect(unit_static($files, '/digits.txt', ['Range' => 'bytes=0-0'])[1])->toBe('0');
    expect(unit_static($files, '/digits.txt', ['Range' => 'bytes=99-99'])[1])->toBe('9');
});

test('odd ranges: a suffix longer than the file is all of it, an inverted or zero suffix is 416', function (string $range, int $status, ?string $contentRange) {
    $response = unit_static(new StaticFiles(unit_public()), '/digits.txt', ['Range' => $range])[0];
    expect([$response->getStatusCode(), $response->getHeaderLine('Content-Range') ?: null])->toBe([$status, $contentRange]);
})->with([
    'suffix past start' => ['bytes=-500', 206, 'bytes 0-99/100'],
    'zero suffix'       => ['bytes=-0', 416, 'bytes */100'],
    'inverted'          => ['bytes=20-10', 416, 'bytes */100'],
    'start at size'     => ['bytes=100-100', 416, 'bytes */100'],
    'bare dash'         => ['bytes=-', 200, null],
    'other unit'        => ['items=0-5', 200, null],
    'spaces'            => ['bytes= 0-5', 200, null],
    'garbage'           => ['bytes=a-b', 200, null],
]);

test('416 has no body and the validators; an empty file has no satisfiable range', function () {
    $files = new StaticFiles(unit_public());
    [$response, $body] = unit_static($files, '/digits.txt', ['Range' => 'bytes=200-']);
    expect([$response->getStatusCode(), $body, $response->getHeaderLine('ETag') !== ''])->toBe([416, '', true]);
    expect(unit_static($files, '/empty.txt', ['Range' => 'bytes=0-0'])[0]->getStatusCode())->toBe(416);
});

test('If-Range with the ETag or the date keeps the range; a stale one gets the whole file', function () {
    $files = new StaticFiles(unit_public());
    $first = unit_static($files, '/digits.txt')[0];
    foreach ([$first->getHeaderLine('ETag'), $first->getHeaderLine('Last-Modified')] as $ifRange) {
        expect(unit_static($files, '/digits.txt', ['Range' => 'bytes=0-9', 'If-Range' => $ifRange])[0]->getStatusCode())->toBe(206);
    }
    [$response, $body] = unit_static($files, '/digits.txt', ['Range' => 'bytes=0-9', 'If-Range' => 'Mon, 01 Jan 2001 00:00:00 GMT']);
    expect([$response->getStatusCode(), strlen($body)])->toBe([200, 100]);
});

test('a range with If-None-Match that matches is 304, not 206', function () {
    $files = new StaticFiles(unit_public());
    $etag  = unit_static($files, '/digits.txt')[0]->getHeaderLine('ETag');
    expect(unit_static($files, '/digits.txt', ['Range' => 'bytes=0-9', 'If-None-Match' => $etag])[0]->getStatusCode())->toBe(304);
});

test('a ranged body reads in pieces, is not seekable and ends at the range', function () {
    $files = new StaticFiles(unit_public());
    phasync::run(function () use ($files) {
        $app      = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(404);
            }
        };
        $response = $files->process(unit_request('/digits.txt', ['Range' => 'bytes=10-24']), $app);
        $body     = $response->getBody();
        expect([$body->getSize(), $body->tell(), $body->eof(), $body->isSeekable(), $body->isWritable(), $body->isReadable()])->toBe([15, 0, false, false, false, true]);
        expect($body->read(4))->toBe('0123');
        expect([$body->tell(), $body->eof()])->toBe([4, false]);
        expect($body->read(1000))->toBe('45678901234');
        expect([$body->tell(), $body->eof(), $body->read(10)])->toBe([15, true, '']);
        expect(fn () => $body->seek(0))->toThrow(RuntimeException::class);
        expect(fn () => $body->rewind())->toThrow(RuntimeException::class);
        expect(fn () => $body->write('x'))->toThrow(RuntimeException::class);
        expect($body->getMetadata())->toBe([])->and($body->getMetadata('uri'))->toBeNull();
        $body->close();
        expect($body->eof())->toBeTrue();
    });
});

test('a ranged body detaches its file, and its string form is the range', function () {
    $files = new StaticFiles(unit_public());
    phasync::run(function () use ($files) {
        $app = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(404);
            }
        };
        $body = $files->process(unit_request('/digits.txt', ['Range' => 'bytes=1-3']), $app)->getBody();
        expect((string) $body)->toBe('123');
        $files->process(unit_request('/digits.txt', ['Range' => 'bytes=1-3']), $app)->getBody();
        $other = $files->process(unit_request('/digits.txt', ['Range' => 'bytes=1-3']), $app)->getBody();
        $fp    = $other->detach();
        expect(is_resource($fp))->toBeTrue();
        fclose($fp);
    });
});

test('a file that cannot be read is answered 403', function () {
    $dir  = unit_public();
    $path = "$dir/locked.txt";
    file_put_contents($path, 'x');
    chmod($path, 0);
    if (is_readable($path)) {
        chmod($path, 0644);
        $this->markTestSkipped('running as a user that reads anything');
    }
    set_error_handler(static fn (): bool => true); // Pest reports the warning fopen() gives, although StaticFiles silences it
    try {
        expect(unit_static(new StaticFiles($dir), '/locked.txt')[0]->getStatusCode())->toBe(403);
    } finally {
        restore_error_handler();
        chmod($path, 0644);
    }
});

test('a file changed after the middleware was made is served as it is now', function () {
    $dir   = unit_public();
    $files = new StaticFiles($dir);
    $first = unit_static($files, '/digits.txt')[0]->getHeaderLine('ETag');
    file_put_contents("$dir/digits.txt", 'longer than before, so the size part of the ETag differs');
    expect(unit_static($files, '/digits.txt')[0]->getHeaderLine('ETag'))->not->toBe($first);
    unlink("$dir/digits.txt");
    expect(unit_static($files, '/digits.txt')[1])->toBe('app');
});

// ---- Cache, without a master: the process's own

test('a stored value keeps its type: null, false, objects and nested arrays', function () {
    $cache = Cache::instance();
    $cache->clear();
    $object = new ArrayObject([1, 2]);
    foreach (['n' => null, 'f' => false, 'z' => 0, 'e' => '', 'a' => ['x' => ['y' => 1.5]], 'o' => $object] as $key => $value) {
        expect($cache->set("unit.$key", $value))->toBeTrue();
    }
    expect($cache->get('unit.n', 'default'))->toBeNull();   // a stored null is not a miss
    expect($cache->has('unit.n'))->toBeTrue();
    expect([$cache->get('unit.f'), $cache->get('unit.z'), $cache->get('unit.e')])->toBe([false, 0, '']);
    expect($cache->get('unit.a'))->toBe(['x' => ['y' => 1.5]]);
    expect($cache->get('unit.o'))->toEqual($object);
    $cache->clear();
});

test('delete and deleteMultiple remove keys, also missing ones, and answer true', function () {
    $cache = Cache::instance();
    $cache->clear();
    $cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);
    expect($cache->delete('a'))->toBeTrue();
    expect($cache->delete('never-set'))->toBeTrue();
    expect($cache->deleteMultiple(['b', 'missing']))->toBeTrue();
    expect($cache->getMultiple(['a', 'b', 'c'], 'gone'))->toBe(['a' => 'gone', 'b' => 'gone', 'c' => 3]);
    $cache->clear();
});

test('getMultiple and setMultiple take generators, and return the keys in the order asked', function () {
    $cache = Cache::instance();
    $cache->clear();
    $cache->setMultiple((static function () {
        yield 'k1' => 'one';
        yield 'k2' => 'two';
    })());
    $values = $cache->getMultiple((static fn () => yield from ['k2', 'k1', 'k3'])(), 0);
    expect($values)->toBe(['k2' => 'two', 'k1' => 'one', 'k3' => 0]);
    expect(array_keys($cache->getMultiple([])))->toBe([]);
    $cache->clear();
});

test('a numeric key given to setMultiple is stored as a string', function () {
    $cache = Cache::instance();
    $cache->clear();
    $cache->setMultiple([10 => 'ten']);
    expect($cache->get('10'))->toBe('ten');
    $cache->clear();
});

test('every key of a multiple call is checked, and nothing is stored when one is bad', function () {
    $cache = Cache::instance();
    $cache->clear();
    expect(fn () => $cache->setMultiple(['ok' => 1, 'bad:key' => 2]))->toThrow(Swerve\CacheKeyException::class);
    expect($cache->has('ok'))->toBeFalse();
    foreach (['a(b', 'a)b', 'a}b', 'a\\b'] as $bad) {
        expect(fn () => $cache->has($bad))->toThrow(Swerve\CacheKeyException::class);
        expect(fn () => $cache->delete($bad))->toThrow(Swerve\CacheKeyException::class);
    }
    expect(fn () => $cache->getMultiple(['fine', '']))->toThrow(Swerve\CacheKeyException::class);
    expect(fn () => $cache->setMultiple([''  => 1]))->toThrow(Swerve\CacheKeyException::class);
    expect($cache->has('a.b-c_d'))->toBeFalse(); // dot, dash and underscore are valid
});

test('a TTL as an integer or a DateInterval expires the entry; a zero or negative one deletes it at once', function () {
    $cache = Cache::instance();
    $cache->clear();
    $cache->set('keep', 1, 3600);
    $cache->set('keep2', 2, new DateInterval('PT1H'));
    expect($cache->getMultiple(['keep', 'keep2']))->toBe(['keep' => 1, 'keep2' => 2]);
    $cache->set('keep', 3, -5);
    expect($cache->has('keep'))->toBeFalse();
    $cache->set('keep2', 4, DateInterval::createFromDateString('-1 minute'));
    expect($cache->has('keep2'))->toBeFalse();
    $cache->setMultiple(['p' => 1, 'q' => 2]);
    $cache->setMultiple(['p' => 9, 'q' => 9], 0);
    expect($cache->getMultiple(['p', 'q']))->toBe(['p' => null, 'q' => null]);
    $cache->clear();
});

test('a TTL of one second expires, and an entry set again without one lives on', function () {
    $cache = Cache::instance();
    $cache->clear();
    $cache->set('short', 'v', 1);
    $cache->set('long', 'v', 60);
    $cache->set('replaced', 'v', 1);
    $cache->set('replaced', 'w'); // a new set replaces the TTL
    expect($cache->has('short'))->toBeTrue();
    usleep(1_050_000);
    expect([$cache->has('short'), $cache->get('short', 'gone'), $cache->has('long'), $cache->get('replaced')])->toBe([false, 'gone', true, 'w']);
    $cache->clear();
});

// ---- Cache, the protocol between a worker and the master

test('serve() carries out a request on the store and has the workers forget the keys written', function () {
    $store  = new LruCache(maxBytes: 1 << 20);
    $forgot = [];
    $forget = static function (?array $keys) use (&$forgot): void {
        $forgot[] = $keys;
    };
    $ask = static fn (array $call): array => (static function (string $reply) {
        return [substr($reply, 0, 4), unserialize(substr($reply, 4))];
    })(Cache::serve($store, new Swerve\Util\Claims(), 0, pack('N', 7) . serialize($call), $forget));

    [$id, $stored] = $ask(['set', ['a' => serialize(1), 'b' => serialize(2)], null]);
    expect([unpack('N', $id)[1], $stored, $forgot])->toBe([7, true, [['a', 'b']]]);
    [, $found] = $ask(['get', ['a', 'c']]);
    expect(array_keys($found))->toBe(['a']);                        // a missing key is not in the reply
    expect(unserialize(substr($found['a'], 8)))->toBe(1);           // 8 bytes of expiry come first
    expect(unpack('q', $found['a'])[1])->toBe(0);                   // none
    expect(count($forgot))->toBe(1);                                // a read forgets nothing
    $ask(['delete', ['a']]);
    $ask(['clear']);
    expect($forgot)->toBe([['a', 'b'], ['a'], null]);
    expect($store->count())->toBe(0);
});

test('serve() expires an entry with a TTL on the monotonic clock, and refuses an unknown request', function () {
    $store = new LruCache(maxBytes: 1 << 20);
    $none  = static function (?array $keys): void {
    };
    Cache::serve($store, new Swerve\Util\Claims(), 0, pack('N', 1) . serialize(['set', ['t' => serialize('v')], 0.15]), $none);
    $get = static fn () => unserialize(substr(Cache::serve($store, new Swerve\Util\Claims(), 0, pack('N', 2) . serialize(['get', ['t']]), $none), 4));
    expect(array_keys($get()))->toBe(['t']);
    usleep(250_000);
    expect($get())->toBe([]);
    expect(fn () => Cache::serve($store, new Swerve\Util\Claims(), 0, pack('N', 3) . serialize(['nonsense']), $none))->toThrow(UnexpectedValueException::class);
});

test('a worker keeps what it read in its local layer, and forget() drops it', function () {
    $cache = Cache::instance();
    $cache->clear();
    $calls = [];
    $store = new LruCache(maxBytes: 1 << 20);
    $store->set('w', pack('q', 0) . serialize('from master'));
    // A fake master: it serves each request at once, and the reply reaches the worker as the pipe's reader would call reply()
    Topics::$toMaster = static function (string $topic, string $message) use (&$calls, $store): void {
        $calls[] = unserialize(substr($message, 4))[0];
        $reply   = Cache::serve($store, new Swerve\Util\Claims(), 0, $message, static function (?array $keys): void {
        });
        phasync::go(static fn () => Cache::reply($reply));
    };
    Cache::$listening = true;
    try {
        phasync::run(static function () use ($cache, &$calls) {
            expect($cache->get('w'))->toBe('from master');
            expect($cache->get('w'))->toBe('from master');
            expect($cache->get('absent', 'dflt'))->toBe('dflt');
            expect($cache->get('absent', 'dflt'))->toBe('dflt');   // a miss is kept too
            expect($calls)->toBe(['get', 'get']);                  // one trip per key, not per read

            Cache::forget(serialize(['absent']));
            expect($cache->get('absent', 'dflt'))->toBe('dflt');
            expect($calls)->toBe(['get', 'get', 'get']);

            expect($cache->set('w', 'new'))->toBeTrue();
            Cache::forget(serialize(['w']));
            expect($cache->get('w'))->toBe('new');
            Cache::forget(serialize(null));                        // everything
            expect($cache->get('w'))->toBe('new');
            expect(end($calls))->toBe('get');
        });
    } finally {
        Topics::$toMaster = null;
        Cache::$listening = false;
        $cache->clear();
    }
});

test('a worker that does not serve yet cannot use the cache through a master', function () {
    $cache            = Cache::instance();
    Topics::$toMaster = static function (): void {
    };
    try {
        expect(fn () => $cache->set('k', 1))->toThrow(LogicException::class);
    } finally {
        Topics::$toMaster = null;
    }
});

test('Swerve::cache() is the one shared instance', function () {
    expect(Swerve\Swerve::cache())->toBe(Cache::instance())->toBeInstanceOf(Psr\SimpleCache\CacheInterface::class);
});

// ---- Dispatcher

function unit_logger(): AbstractLogger
{
    return new class extends AbstractLogger {
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = [$level, (string) $message, $context];
        }
    };
}

function unit_responder(array &$events): ResponderInterface
{
    return new class($events) implements ResponderInterface {
        public ?ServerRequestInterface $request   = null;
        public ?ResponseInterface $response = null;

        public function __construct(private array &$events)
        {
        }

        public function respond(ServerRequestInterface $request, ResponseInterface $response): mixed
        {
            $this->request  = $request;
            $this->response = $response;
            $this->events[] = 'respond';

            return 'sent ' . $response->getStatusCode();
        }
    };
}

function unit_handler(Closure $fn): RequestHandlerInterface
{
    return new class($fn) implements RequestHandlerInterface {
        public function __construct(private readonly Closure $fn)
        {
        }

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return ($this->fn)($request);
        }
    };
}

test('the dispatcher gives the responder the request and the handler\'s response, and returns what it returns', function () {
    $events    = [];
    $responder = unit_responder($events);
    $response  = new Response(202, [], 'x');
    $request   = unit_request('/a');
    $dispatcher = new Dispatcher(unit_handler(static function ($r) use ($request, $response, &$events) {
        $events[] = 'handle';
        expect($r)->toBe($request);

        return $response;
    }), unit_logger());
    $result = phasync::run(static fn () => $dispatcher->dispatch($request, $responder));
    expect([$result, $events, $responder->request, $responder->response])->toBe(['sent 202', ['handle', 'respond'], $request, $response]);
});

test('a handler that never asks for a context runs in the caller\'s own, one that does gets a request context', function () {
    $outer = null;
    $seen  = [];
    $dispatcher = new Dispatcher(unit_handler(static function ($r) use (&$seen) {
        if ('/ctx' === $r->getUri()->getPath()) {
            $seen[] = phasync::getContext();
        }

        return new Response(200);
    }), unit_logger());
    $events    = [];
    $responder = unit_responder($events);
    phasync::run(static function () use ($dispatcher, $responder, &$outer, &$seen) {
        $outer = phasync::getContext();
        $dispatcher->dispatch(unit_request('/plain'), $responder);
        $dispatcher->dispatch(unit_request('/ctx'), $responder);
        $dispatcher->dispatch(unit_request('/ctx'), $responder);
        expect(phasync::getContext())->toBe($outer);                 // left again
    });
    expect($seen)->toHaveCount(2);
    expect($seen[0])->toBeInstanceOf(Swerve\Util\LoggingContext::class)->not->toBe($outer)->not->toBe($seen[1]);
});

test('phasync::finally() in the handler runs after the response is sent, last registered first', function () {
    $events     = [];
    $responder  = unit_responder($events);
    $dispatcher = new Dispatcher(unit_handler(static function () use (&$events) {
        phasync::finally(static function () use (&$events) {
            $events[] = 'finally 1';
        });
        phasync::finally(static function () use (&$events) {
            $events[] = 'finally 2';
        });
        $events[] = 'handle';

        return new Response(200);
    }), unit_logger());
    $result = phasync::run(static function () use ($dispatcher, $responder, &$events) {
        $result   = $dispatcher->dispatch(unit_request('/'), $responder);
        $events[] = 'returned';

        return $result;
    });
    expect([$result, $events])->toBe(['sent 200', ['handle', 'respond', 'finally 2', 'finally 1', 'returned']]);
});

test('a handler that throws: the responder is not called, finally() runs, the exception is the caller\'s', function () {
    $events     = [];
    $responder  = unit_responder($events);
    $dispatcher = new Dispatcher(unit_handler(static function () use (&$events) {
        phasync::finally(static function () use (&$events) {
            $events[] = 'finally';
        });
        throw new DomainException('boom');
    }), unit_logger());
    expect(static fn () => phasync::run(static fn () => $dispatcher->dispatch(unit_request('/'), $responder)))->toThrow(DomainException::class, 'boom');
    expect([$events, $responder->response])->toBe([['finally'], null]);
});

test('a responder that throws: finally() still runs and the exception reaches the caller', function () {
    $events     = [];
    $dispatcher = new Dispatcher(unit_handler(static function () use (&$events) {
        phasync::finally(static function () use (&$events) {
            $events[] = 'finally';
        });

        return new Response(200);
    }), unit_logger());
    $responder = new class implements ResponderInterface {
        public function respond(ServerRequestInterface $request, ResponseInterface $response): mixed
        {
            throw new RuntimeException('connection lost');
        }
    };
    expect(static fn () => phasync::run(static fn () => $dispatcher->dispatch(unit_request('/'), $responder)))->toThrow(RuntimeException::class, 'connection lost');
    expect($events)->toBe(['finally']);
});

test('a responder may suspend: the dispatch returns once it has', function () {
    $dispatcher = new Dispatcher(unit_handler(static fn () => new Response(200)), unit_logger());
    $responder  = new class implements ResponderInterface {
        public function respond(ServerRequestInterface $request, ResponseInterface $response): mixed
        {
            phasync::sleep(0.02);

            return 'slept';
        }
    };
    expect(phasync::run(static fn () => $dispatcher->dispatch(unit_request('/'), $responder)))->toBe('slept');
});

test('dispatch() returns once the coroutines the handler started have ended, after the response was sent', function () {
    $ended      = false;
    $dispatcher = new Dispatcher(unit_handler(static function () use (&$ended) {
        phasync::go(static function () use (&$ended) {
            phasync::sleep(0.1);
            $ended = true;
        });

        return new Response(200);
    }), unit_logger());
    $events    = [];
    $responder = unit_responder($events);
    $sentBefore = null;
    phasync::run(static function () use ($dispatcher, $responder, &$ended, &$events, &$sentBefore) {
        phasync::go(static function () use (&$ended, &$events, &$sentBefore) {
            phasync::sleep(0.02);
            $sentBefore = !$ended && [] !== $events;
        });
        $dispatcher->dispatch(unit_request('/'), $responder);
        expect($ended)->toBeTrue();
    });
    expect($sentBefore)->toBeTrue();
});

test('a background coroutine that fails is logged with the dispatcher\'s logger, not thrown', function () {
    $logger     = unit_logger();
    $dispatcher = new Dispatcher(unit_handler(static function () {
        phasync::go(static function () {
            throw new LogicException('background');
        });

        return new Response(200);
    }), $logger);
    $events    = [];
    $responder = unit_responder($events);
    phasync::run(static function () use ($dispatcher, $responder) {
        $dispatcher->dispatch(unit_request('/'), $responder);
        phasync::sleep(0.05);
    });
    expect($logger->records)->toHaveCount(1);
    expect([$logger->records[0][0], $logger->records[0][2]['exception']->getMessage()])->toBe(['error', 'background']);
});

test('concurrent dispatches each get a context of their own', function () {
    $contexts   = [];
    $dispatcher = new Dispatcher(unit_handler(static function ($r) use (&$contexts) {
        $contexts[$r->getUri()->getPath()] = phasync::getContext();
        phasync::sleep(0.01);
        expect(phasync::getContext())->toBe($contexts[$r->getUri()->getPath()]);

        return new Response(200);
    }), unit_logger());
    $events    = [];
    $responder = unit_responder($events);
    phasync::run(static function () use ($dispatcher, $responder) {
        foreach (['/a', '/b', '/c'] as $path) {
            phasync::go(static fn () => $dispatcher->dispatch(unit_request($path), $responder));
        }
    });
    expect($contexts)->toHaveCount(3);
    expect(count(array_unique(array_map('spl_object_id', $contexts))))->toBe(3);
});
