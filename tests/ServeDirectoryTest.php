<?php

/*
 * swerve -S [dir] [router.php]: php -S's way of serving a directory, and the error pages swerve
 * answers with itself.
 */

/**
 * Start `swerve -S ...$args` in $cwd and wait until it answers /a.txt.
 *
 * @return array{0: resource, 1: string}
 */
function serve_start(string $cwd, array $args = []): array
{
    $addr     = free_address();
    $process  = swerve_spawn(["--http=$addr", '--workers=1', '-S', ...$args], null, cwd: $cwd);
    $deadline = microtime(true) + 10;
    while (null === http_get($addr, '/a.txt')) {
        if (microtime(true) > $deadline || !proc_get_status($process)['running']) {
            throw new RuntimeException("swerve -S did not start serving on $addr");
        }
        usleep(20000);
    }

    return [$process, $addr];
}

/** A response to $request on a fresh connection. */
function serve_request(string $addr, string $request): array
{
    $conn = native_connect($addr);
    fwrite($conn, $request);

    return native_read_response($conn, str_starts_with($request, 'HEAD '));
}

/** The body of a 200 to a GET of $path. */
function serve_get(string $addr, string $path): ?string
{
    $response = serve_request($addr, "GET $path HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");

    return 200 === $response['status'] ? $response['body'] : null;
}

function serve_site(bool $index = false): string
{
    $dir = sys_get_temp_dir() . '/swerve-serve-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir("$dir/sub", 0o777, true);
    file_put_contents("$dir/a.txt", "a file\n");
    file_put_contents("$dir/sub/x.php", '<?php echo "x.php ", $_SERVER["SCRIPT_NAME"];');
    file_put_contents("$dir/router.php", '<?php echo "router ", $_SERVER["REQUEST_URI"];');
    if ($index) {
        file_put_contents("$dir/index.php", '<?php echo "index ", $_SERVER["REQUEST_URI"];');
    }
    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($dir)));

    return $dir;
}

test('swerve -S serves the current directory: its files, its PHP files run, the rest 404 without index.php', function () {
    [$process, $addr] = serve_start(serve_site());
    try {
        expect(serve_get($addr, '/a.txt'))->toBe("a file\n");
        expect(serve_get($addr, '/sub/x.php'))->toBe('x.php /sub/x.php');
        $missing = serve_request($addr, "GET /missing HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
        expect([$missing['status'], $missing['headers']['content-type'], $missing['body']])
            ->toBe([404, 'text/plain; charset=utf-8', "404 Not Found\nThe requested URL was not found on this server.\n"]);
    } finally {
        native_stop($process);
    }
});

test('swerve -S runs the directory\'s index.php for what is not a file, or the router given', function () {
    $dir = serve_site(index: true);
    [$process, $addr] = serve_start($dir);
    try {
        expect(serve_get($addr, '/missing'))->toBe('index /missing');
        expect(serve_get($addr, '/sub/x.php'))->toBe('x.php /sub/x.php');
    } finally {
        native_stop($process);
    }
    [$process, $addr] = serve_start('/', [$dir, "$dir/router.php"]);
    try {
        expect(serve_get($addr, '/missing'))->toBe('router /missing');
    } finally {
        native_stop($process);
    }
});

test('a browser gets an HTML error page signed with swerve\'s version; HEAD gets the head only', function () {
    [$process, $addr] = serve_start(serve_site());
    try {
        $page = serve_request($addr, "GET /missing HTTP/1.1\r\nHost: t\r\nAccept: text/html,*/*\r\nConnection: close\r\n\r\n");
        expect($page['status'])->toBe(404);
        expect($page['headers']['content-type'])->toBe('text/html; charset=utf-8');
        expect($page['body'])->toContain('<title>404 Not Found</title>')
            ->toContain('<footer>Swerve/' . htmlspecialchars(Swerve\Swerve::getVersion()) . '</footer>');

        $head = serve_request($addr, "HEAD /missing HTTP/1.1\r\nHost: t\r\nAccept: text/html\r\nConnection: close\r\n\r\n");
        expect([$head['status'], $head['headers']['content-length']])->toBe([404, (string) strlen($page['body'])]);
    } finally {
        native_stop($process);
    }
});

test('a refused request gets its error page', function () {
    [$process, $addr] = serve_start(serve_site());
    try {
        $conn = native_connect($addr);
        fwrite($conn, "GET /a.txt HTTP/1.1\r\n\r\n"); // HTTP/1.1 without Host
        $response = native_read_response($conn);
        expect([$response['status'], $response['body']])->toBe([400, "400 Bad Request\nThe server could not understand the request.\n"]);
    } finally {
        native_stop($process);
    }
});

test('-S takes a directory and a router at most, and no swerve.php', function () {
    $dir     = serve_site();
    $process = swerve_spawn(['--http=' . free_address(), '-S', $dir, "$dir/router.php", "$dir/sub/x.php"], null, out: [2 => ['pipe', 'w']]);
    expect(proc_close($process))->toBe(1);
});
