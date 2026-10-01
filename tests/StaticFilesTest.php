<?php

use phasync\Psr\Response;
use phasync\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
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

function static_get(StaticFiles $files, string $target, array $headers = [], string $method = 'GET'): ResponseInterface
{
    $app = new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new Response(404, [], 'app');
        }
    };

    return $files->process(new ServerRequest($method, $target, '', $headers), $app);
}

test('a file is served with its type, length, Last-Modified and ETag; anything else goes to the application', function () {
    $files = new StaticFiles(static_dir());
    $js    = static_get($files, '/app.js');
    expect([$js->getStatusCode(), $js->getHeaderLine('Content-Type'), $js->getHeaderLine('Content-Length'), (string) $js->getBody()])
        ->toBe([200, 'text/javascript; charset=utf-8', '14', 'console.log(1)']);
    expect($js->getHeaderLine('ETag'))->toMatch('/^"[0-9a-f]+-e"$/');
    expect($js->getHeaderLine('Last-Modified'))->toEndWith(' GMT');

    $seen = [];
    foreach (['/', '/docs/', '/missing.css', '/.env', '/empty/', '/link.txt', '/../secret.txt', '/%2e%2e/secret.txt', '/.well-known/security.txt'] as $target) {
        $seen[$target] = (string) static_get($files, $target)->getBody();
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
    expect((string) static_get($files, '/app.js', method: 'POST')->getBody())->toBe('app');
});

test('a directory without its trailing slash is redirected to it, keeping the query', function () {
    $response = static_get(new StaticFiles(static_dir()), '/docs?a=1');
    expect([$response->getStatusCode(), $response->getHeaderLine('Location')])->toBe([301, '/docs/?a=1']);
});

test('If-None-Match and If-Modified-Since answer 304', function () {
    $files = new StaticFiles(static_dir());
    $first = static_get($files, '/app.js');
    expect(static_get($files, '/app.js', ['If-None-Match' => 'W/' . $first->getHeaderLine('ETag')])->getStatusCode())->toBe(304);
    expect(static_get($files, '/app.js', ['If-None-Match' => '"other"'])->getStatusCode())->toBe(200);
    expect(static_get($files, '/app.js', ['If-Modified-Since' => $first->getHeaderLine('Last-Modified')])->getStatusCode())->toBe(304);
    expect(static_get($files, '/app.js', ['If-Modified-Since' => 'Mon, 01 Jan 2001 00:00:00 GMT'])->getStatusCode())->toBe(200);
});

test('a byte range is served as 206, past the end as 416; If-Range that does not match gets the whole file', function (string $range, int $status, ?string $contentRange, ?array $bytes, string $ifRange = '') {
    $files    = new StaticFiles(static_dir());
    $response = static_get($files, '/data.bin', ['Range' => $range] + ('' !== $ifRange ? ['If-Range' => $ifRange] : []));
    expect($response->getStatusCode())->toBe($status);
    if (null !== $contentRange) {
        expect($response->getHeaderLine('Content-Range'))->toBe($contentRange);
    }
    if (null !== $bytes) {
        $body = (string) $response->getBody();
        expect([strlen($body), ord($body[0]), (int) $response->getHeaderLine('Content-Length')])->toBe($bytes);
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

test('--public needs a directory, and HTTP', function () {
    $swerve = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/swerve.php');
    exec("$swerve --public=/no/such/dir 2>&1", $out, $code);
    expect([$code, $out[0]])->toBe([2, 'swerve: Illegal value for option: --public: /no/such/dir is not a directory']);
    $out = [];
    exec("$swerve --public=/tmp --fastcgi=9000 " . escapeshellarg(__DIR__ . '/Fixtures/app.php') . ' 2>&1', $out, $code);
    expect([$code, $out[0]])->toBe([2, 'swerve: --buffer-responses, --max-body and --public only apply to --http']);
});

test('a PHP file in the public directory is passed to the application, never sent as source', function () {
    $dir = static_dir();
    foreach (['index.php', 'config.PHP', 'page.phtml', 'tool.phar', 'lib.inc', 'old.php5'] as $name) {
        file_put_contents("$dir/$name", '<?php $secret = 1;');
    }
    $files = new StaticFiles($dir);
    foreach (['/index.php', '/config.PHP', '/page.phtml', '/tool.phar', '/lib.inc', '/old.php5'] as $target) {
        expect((string) static_get($files, $target)->getBody())->toBe('app');
    }
});
