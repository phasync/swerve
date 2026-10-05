<?php

use Swerve\ClientRequest;
use Swerve\StaticFiles;

/**
 * A public directory: index.html, app.js, a 1000-byte data.bin, a sub directory with and
 * without index.html, a dotfile, and a symlink out of it.
 */
function static_dir(): string
{
    $dir = temp_path(true);
    mkdir("$dir/public/docs", 0777, true);
    mkdir("$dir/public/empty");
    mkdir("$dir/public/.well-known");
    file_put_contents("$dir/public/index.html", '<h1>home</h1>');
    file_put_contents("$dir/public/app.js", 'console.log(1)');
    file_put_contents("$dir/public/data.bin", implode('', array_map(static fn ($i) => chr($i % 256), range(0, 999))));
    file_put_contents("$dir/public/docs/index.html", 'docs');
    file_put_contents("$dir/public/.env", 'SECRET=1');
    file_put_contents("$dir/public/.well-known/security.txt", 'contact');
    file_put_contents("$dir/secret.txt", 'outside');
    symlink("$dir/secret.txt", "$dir/public/link.txt");

    return "$dir/public";
}

/**
 * One request through the files' handler, over a connection, the application answering 404 "app".
 *
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function static_get(StaticFiles $files, string $target, array $headers = [], string $method = 'GET'): array
{
    $app = static function (ClientRequest $r) {
        $r->sendResponseHeaders(404, ['Content-Length' => '3']);
        $r->write('app');
    };
    $request = "$method $target HTTP/1.1\r\nHost: t\r\nConnection: close\r\n";
    foreach ($headers as $name => $value) {
        $request .= "$name: $value\r\n";
    }
    $raw                 = implode('', native_serve_packets($files->wrap($app), "$request\r\n"));
    [$head, $body]       = explode("\r\n\r\n", $raw, 2);
    $lines               = explode("\r\n", $head);
    $status              = (int) substr(array_shift($lines), 9, 3);
    $parsed              = [];
    foreach ($lines as $line) {
        [$name, $value]                 = explode(': ', $line, 2);
        $parsed[strtolower($name)] = $value;
    }

    return ['status' => $status, 'headers' => $parsed, 'body' => $body];
}

test('a file is served with its type, length, Last-Modified and ETag; anything else goes to the application', function () {
    $files = new StaticFiles(static_dir());
    $js    = static_get($files, '/app.js');
    expect([$js['status'], $js['headers']['content-type'], $js['headers']['content-length'], $js['body']])
        ->toBe([200, 'text/javascript; charset=utf-8', '14', 'console.log(1)']);
    expect($js['headers']['etag'])->toMatch('/^"[0-9a-f]+-e"$/');
    expect($js['headers']['last-modified'])->toEndWith(' GMT');

    $seen = [];
    foreach (['/', '/docs/', '/missing.css', '/.env', '/empty/', '/link.txt', '/../secret.txt', '/%2e%2e/secret.txt', '/.well-known/security.txt'] as $target) {
        $seen[$target] = static_get($files, $target)['body'];
    }
    expect($seen)->toBe([
        '/'                         => '<h1>home</h1>',
        '/docs/'                    => 'docs',
        '/missing.css'              => 'app',
        '/.env'                     => 'app',
        '/empty/'                   => 'app',
        '/link.txt'                 => 'app',
        '/../secret.txt'            => 'app',
        '/%2e%2e/secret.txt'        => 'app',
        '/.well-known/security.txt' => 'contact',
    ]);
    expect(static_get($files, '/app.js', method: 'POST')['body'])->toBe('app');
});

test('a directory without its trailing slash is redirected to it, keeping the query', function () {
    $response = static_get(new StaticFiles(static_dir()), '/docs?a=1');
    expect([$response['status'], $response['headers']['location']])->toBe([301, '/docs/?a=1']);
});

test('If-None-Match and If-Modified-Since answer 304', function () {
    $files = new StaticFiles(static_dir());
    $first = static_get($files, '/app.js');
    expect(static_get($files, '/app.js', ['If-None-Match' => 'W/' . $first['headers']['etag']])['status'])->toBe(304);
    expect(static_get($files, '/app.js', ['If-None-Match' => '"other"'])['status'])->toBe(200);
    expect(static_get($files, '/app.js', ['If-Modified-Since' => $first['headers']['last-modified']])['status'])->toBe(304);
    expect(static_get($files, '/app.js', ['If-Modified-Since' => 'Mon, 01 Jan 2001 00:00:00 GMT'])['status'])->toBe(200);
});

test('a byte range is served as 206, past the end as 416; If-Range that does not match gets the whole file', function (string $range, int $status, ?string $contentRange, ?array $bytes, string $ifRange = '') {
    $files    = new StaticFiles(static_dir());
    $response = static_get($files, '/data.bin', ['Range' => $range] + ('' !== $ifRange ? ['If-Range' => $ifRange] : []));
    expect($response['status'])->toBe($status);
    if (null !== $contentRange) {
        expect($response['headers']['content-range'])->toBe($contentRange);
    }
    if (null !== $bytes) {
        $body = $response['body'];
        expect([strlen($body), ord($body[0]), (int) $response['headers']['content-length']])->toBe($bytes);
    }
})->with([
    'from to'        => ['bytes=10-19', 206, 'bytes 10-19/1000', [10, 10, 10]],
    'from'           => ['bytes=990-', 206, 'bytes 990-999/1000', [10, 990 % 256, 10]],
    'last n'         => ['bytes=-5', 206, 'bytes 995-999/1000', [5, 995 % 256, 5]],
    'to past end'    => ['bytes=998-5000', 206, 'bytes 998-999/1000', [2, 998 % 256, 2]],
    'past the end'   => ['bytes=1000-', 416, 'bytes */1000', null],
    'several ranges' => ['bytes=0-1,5-6', 200, null, [1000, 0, 1000]],
    'stale If-Range' => ['bytes=10-19', 200, null, [1000, 0, 1000], '"old"'],
]);

test('with --public, swerve serves the files and the application the rest, HEAD included; a range streams from its start', function () {
    $dir = static_dir();
    [$process, $addr] = swerve_start(["--public=$dir"], 1);
    try {
        expect(probe($addr, '/app.js'))->toBe('console.log(1)');
        expect(probe($addr, '/hello'))->toBe('Hello');

        $conn = native_connect($addr);
        fwrite($conn, "GET /data.bin HTTP/1.1\r\nHost: t\r\nRange: bytes=500-503\r\n\r\n");
        $response = native_read_response($conn);
        expect([$response['status'], bin2hex($response['body'])])->toBe([206, bin2hex(chr(500 % 256) . chr(501 % 256) . chr(502 % 256) . chr(503 % 256))]);

        fwrite($conn, "HEAD /app.js HTTP/1.1\r\nHost: t\r\n\r\n");
        $response = native_read_response($conn, head: true);
        expect([$response['status'], $response['headers']['content-length'], $response['body']])->toBe([200, '14', '']);
    } finally {
        native_stop($process);
    }
});

test('a relative --public is the directory where swerve started, also when the application changes directory as it loads', function () {
    $dir = static_dir();
    $cwd = getcwd();
    chdir(dirname($dir));
    try {
        [$process, $addr] = swerve_start(['--public=' . basename($dir)], 1, fixture: 'chdir-app.php');
    } finally {
        chdir($cwd);
    }
    try {
        expect(probe($addr, '/app.js'))->toBe('console.log(1)');
    } finally {
        native_stop($process);
    }
});

test('--public needs a directory', function () {
    $swerve = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/swerve.php');
    exec("$swerve --public=/no/such/dir 2>&1", $out, $code);
    expect([$code, $out[0]])->toBe([2, 'swerve: Illegal value for option: --public: /no/such/dir is not a directory']);
});

test('a PHP file in the public directory is passed to the application, never sent as source', function () {
    $dir = static_dir();
    foreach (['index.php', 'config.PHP', 'page.phtml', 'tool.phar', 'lib.inc', 'old.php5'] as $name) {
        file_put_contents("$dir/$name", '<?php $secret = 1;');
    }
    $files = new StaticFiles($dir);
    foreach (['/index.php', '/config.PHP', '/page.phtml', '/tool.phar', '/lib.inc', '/old.php5'] as $target) {
        expect(static_get($files, $target)['body'])->toBe('app');
    }
});

// ---- The handler called directly, over a connection of its own

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

/** Run the files' handler, with an application answering 404 "app"; returns the response and its body. */
function unit_static(StaticFiles $files, string $target, array $headers = [], string $method = 'GET'): array
{
    $response = static_get($files, $target, $headers, $method);

    return [$response, $response['body']];
}

test('a directory that does not exist is no public directory', function () {
    expect(fn () => new StaticFiles('/no/such/dir'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new StaticFiles(__FILE__))->toThrow(InvalidArgumentException::class);
});

test('the type comes from the extension, in any case; unknown or none is octet-stream', function () {
    $files = new StaticFiles(unit_public());
    $types = [];
    foreach (['/UPPER.CSS', '/noext', '/thing.unknown', '/digits.txt', '/index.html'] as $target) {
        $types[$target] = (unit_static($files, $target)[0]['headers']['content-type'] ?? '');
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
    expect([$response['status'], $body, ($response['headers']['accept-ranges'] ?? '')])->toBe([200, 'spaced', 'bytes']);
    expect(unit_static($files, '/inlink.txt')[0]['status'])->toBe(200); // a symlink within the directory
});

test('an empty file is served with a Content-Length of 0', function () {
    [$response, $body] = unit_static(new StaticFiles(unit_public()), '/empty.txt');
    expect([$response['status'], ($response['headers']['content-length'] ?? ''), $body])->toBe([200, '0', '']);
});

test('HEAD gets the headers of a GET', function () {
    $files = new StaticFiles(unit_public());
    $get   = unit_static($files, '/digits.txt')[0];
    $head  = unit_static($files, '/digits.txt', method: 'HEAD')[0];
    expect([$head['status'], ($head['headers']['content-length'] ?? ''), ($head['headers']['etag'] ?? '')])
        ->toBe([200, '100', ($get['headers']['etag'] ?? '')]);
    // The application's own 404 (a HEAD response has no body on the wire)
    expect(unit_static($files, '/missing.txt', method: 'HEAD')[0]['headers']['content-length'])->toBe('3');
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
    expect([$response['status'], ($response['headers']['location'] ?? ''), $body])->toBe([301, '/docs/', '']);
});

test('a file named with a trailing slash is not served', function () {
    expect(unit_static(new StaticFiles(unit_public()), '/digits.txt/')[1])->toBe('app');
});

test('If-None-Match: a list, weak tags and * match; the 304 keeps the validators and has no body', function () {
    $files = new StaticFiles(unit_public());
    $etag  = (unit_static($files, '/digits.txt')[0]['headers']['etag'] ?? '');
    foreach (["\"a\", $etag", "W/$etag", '*', " $etag "] as $header) {
        [$response, $body] = unit_static($files, '/digits.txt', ['If-None-Match' => $header]);
        expect([$response['status'], $body, ($response['headers']['etag'] ?? '')])->toBe([304, '', $etag], $header);
    }
    expect(unit_static($files, '/digits.txt', ['If-None-Match' => '"a", "b"'])[0]['status'])->toBe(200);
    expect(unit_static($files, '/digits.txt', ['If-None-Match' => $etag], 'HEAD')[0]['status'])->toBe(304);
});

test('If-None-Match wins over If-Modified-Since; an unparsable If-Modified-Since is ignored', function () {
    $files    = new StaticFiles(unit_public());
    $modified = (unit_static($files, '/digits.txt')[0]['headers']['last-modified'] ?? '');
    expect(unit_static($files, '/digits.txt', ['If-None-Match' => '"nope"', 'If-Modified-Since' => $modified])[0]['status'])->toBe(200);
    expect(unit_static($files, '/digits.txt', ['If-Modified-Since' => 'not a date'])[0]['status'])->toBe(200);
    expect(unit_static($files, '/digits.txt', ['If-Modified-Since' => 'Fri, 01 Jan 2100 00:00:00 GMT'])[0]['status'])->toBe(304);
});

test('a byte range: its Content-Type and validators stay, the body is exactly the range', function () {
    $files = new StaticFiles(unit_public());
    [$response, $body] = unit_static($files, '/digits.txt', ['Range' => 'bytes=5-14']);
    expect([$response['status'], $body, ($response['headers']['content-range'] ?? ''), ($response['headers']['content-type'] ?? '')])
        ->toBe([206, '5678901234', 'bytes 5-14/100', 'text/plain; charset=utf-8']);
    expect(($response['headers']['etag'] ?? ''))->not->toBe('');
    expect(unit_static($files, '/digits.txt', ['Range' => 'bytes=0-0'])[1])->toBe('0');
    expect(unit_static($files, '/digits.txt', ['Range' => 'bytes=99-99'])[1])->toBe('9');
});

test('odd ranges: a suffix longer than the file is all of it, an inverted or zero suffix is 416', function (string $range, int $status, ?string $contentRange) {
    $response = unit_static(new StaticFiles(unit_public()), '/digits.txt', ['Range' => $range])[0];
    expect([$response['status'], ($response['headers']['content-range'] ?? '') ?: null])->toBe([$status, $contentRange]);
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
    expect([$response['status'], $body, ($response['headers']['etag'] ?? '') !== ''])->toBe([416, '', true]);
    expect(unit_static($files, '/empty.txt', ['Range' => 'bytes=0-0'])[0]['status'])->toBe(416);
});

test('If-Range with the ETag or the date keeps the range; a stale one gets the whole file', function () {
    $files = new StaticFiles(unit_public());
    $first = unit_static($files, '/digits.txt')[0];
    foreach ([($first['headers']['etag'] ?? ''), ($first['headers']['last-modified'] ?? '')] as $ifRange) {
        expect(unit_static($files, '/digits.txt', ['Range' => 'bytes=0-9', 'If-Range' => $ifRange])[0]['status'])->toBe(206);
    }
    [$response, $body] = unit_static($files, '/digits.txt', ['Range' => 'bytes=0-9', 'If-Range' => 'Mon, 01 Jan 2001 00:00:00 GMT']);
    expect([$response['status'], strlen($body)])->toBe([200, 100]);
});

test('a range with If-None-Match that matches is 304, not 206', function () {
    $files = new StaticFiles(unit_public());
    $etag  = (unit_static($files, '/digits.txt')[0]['headers']['etag'] ?? '');
    expect(unit_static($files, '/digits.txt', ['Range' => 'bytes=0-9', 'If-None-Match' => $etag])[0]['status'])->toBe(304);
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
        expect(unit_static(new StaticFiles($dir), '/locked.txt')[0]['status'])->toBe(403);
    } finally {
        restore_error_handler();
        chmod($path, 0644);
    }
});

test('a file changed after the middleware was made is served as it is now', function () {
    $dir   = unit_public();
    $files = new StaticFiles($dir);
    $first = (unit_static($files, '/digits.txt')[0]['headers']['etag'] ?? '');
    file_put_contents("$dir/digits.txt", 'longer than before, so the size part of the ETag differs');
    expect((unit_static($files, '/digits.txt')[0]['headers']['etag'] ?? ''))->not->toBe($first);
    unlink("$dir/digits.txt");
    expect(unit_static($files, '/digits.txt')[1])->toBe('app');
});
