<?php

/*
 * Nothing that serves the requests is started by a request: a coroutine belongs to the request
 * of the coroutine that starts it (sapi()), and a service started there would belong to that
 * request for the life of the worker. The services start with the lifecycle (pub/sub's reader,
 * the limited services' listener, the WebSocket pings) and are only signalled by requests; the
 * connections' and the requests' coroutines are started by accept() and the connections.
 */

/** GET $path on a connection of its own: the response body. */
function services_get(string $addr, string $path): ?string
{
    $conn = native_connect($addr);
    fwrite($conn, "GET $path HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");

    return native_read_response($conn)['body'] ?? null;
}

test('phasync::service() is called only where no request runs, and swerve starts no Fiber itself', function () {
    $allowed = [
        'Swerve\Http\RequestPool::run',          // from a connection's coroutine, never inside a request (it throws)
        'Swerve\Lot\LotClient::assign',          // accept() and the connections' coroutines
        'Swerve\Lot\LotClient::startServices',
        'Swerve\Util\SharedLog::service',        // the lifecycle's start
        'Swerve\Container::service',
        'Swerve\WebSocket::service',
        'Swerve\WebSocket::keepAlive',           // outside a lifecycle only: the lifecycle's ping coroutine runs
        'Swerve\Lot\Supervisor::*',              // the master, which serves no request
    ];
    $found = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../src', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ('php' !== $file->getExtension()) {
            continue;
        }
        $tokens    = token_get_all(file_get_contents($file->getPathname()));
        $namespace = $class = $function = '';
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            $next = static function (int $i) use ($tokens) {
                for (++$i; isset($tokens[$i]); ++$i) {
                    if (!is_array($tokens[$i]) || T_WHITESPACE !== $tokens[$i][0]) {
                        return $tokens[$i];
                    }
                }

                return null;
            };
            switch ($token[0]) {
                case T_NAMESPACE:
                    $namespace = $next($i)[1];
                    break;
                case T_CLASS:
                    $name  = $next($i);
                    $class = is_array($name) && T_STRING === $name[0] ? "$namespace\\$name[1]" : $class;
                    break;
                case T_FUNCTION:
                    $name     = $next($i);
                    $function = is_array($name) && T_STRING === $name[0] ? $name[1] : $function; // a closure is its method's
                    break;
                case T_NEW:
                    $name = $next($i);
                    expect(is_array($name) ? ltrim($name[1], '\\') : '')->not->toBe('Fiber', "new Fiber in $class::$function");
                    break;
                case T_STRING:
                    if ('service' === $token[1] && is_array($tokens[$i - 2] ?? null) && 'phasync' === ltrim($tokens[$i - 2][1], '\\') && '(' === $next($i)) {
                        $found[] = "$class::$function";
                    }
            }
        }
    }
    $outside = array_values(array_filter(array_unique($found), static fn ($at) => !in_array($at, $allowed, true) && !in_array(explode('::', $at)[0] . '::*', $allowed, true)));

    expect($found)->not->toBeEmpty()
        ->and($outside)->toBe([]);
});

test('a request that is the first to need pub/sub, or a limited service, leaves them working for every request, in every worker', function () {
    [$process, $addr, $log] = swerve_start(workers: 2, fixture: 'services.php');
    try {
        // Subscribers in both workers, each the first subscription of its worker, from a request
        $subscribers = [];
        for ($i = 0; $i < 4; ++$i) {
            $subscribers[] = $conn = native_connect($addr);
            fwrite($conn, "GET /subscribe HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
        }
        usleep(300_000);
        expect(services_get($addr, '/publish?m=first'))->toBe('published');
        foreach ($subscribers as $conn) {
            expect(native_read_response($conn)['body'])->toBe('first');
        }
        // Again: the readers started by those requests still read, for requests of their own
        $conn = native_connect($addr);
        fwrite($conn, "GET /subscribe HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
        usleep(200_000);
        expect(services_get($addr, '/publish?m=second'))->toBe('published')
            ->and(native_read_response($conn)['body'])->toBe('second');

        // The limited service, added by the first request that takes it in each worker
        $conns = [];
        for ($i = 0; $i < 6; ++$i) {
            $conns[] = native_connect($addr);
            fwrite(end($conns), "GET /hold?ms=150 HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
        }
        $held = $pids = [];
        foreach ($conns as $conn) {
            $response = native_read_response($conn);
            expect($response['status'] ?? null)->toBe(200, (string) file_get_contents($log));
            preg_match('/^(\d+) ([\d.]+)\n([\d.]+)$/', $response['body'], $m);
            $pids[$m[1]] = true;
            $held[]      = [(float) $m[2], (float) $m[3]];
        }
        sort($held);
        for ($i = 1; $i < count($held); ++$i) {
            expect($held[$i][0])->toBeGreaterThanOrEqual($held[$i - 1][1]); // never two at once
            expect($held[$i][0] - $held[$i - 1][1])->toBeLessThan(0.5);     // the next is woken by the release, in whichever worker
        }
        expect(count($pids))->toBe(2, 'both workers served: otherwise this shows nothing across workers');
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|WARNING|Warning:)/'))->toBe(0, (string) file_get_contents($log));
});

test('a request that subscribed to a topic first is freed once it ends, while a later subscriber keeps the topic', function () {
    [$process, $addr] = swerve_start(workers: 1, fixture: 'services.php');
    try {
        $freed = temp_path();
        $first = native_connect($addr);
        fwrite($first, "GET /subscribe?freed=" . urlencode($freed) . " HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
        usleep(150_000);
        $second = native_connect($addr);
        fwrite($second, "GET /subscribe?n=2 HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
        usleep(150_000);
        expect(services_get($addr, '/publish?m=one'))->toBe('published')
            ->and(native_read_response($first)['body'] ?? null)->toBe('one');
        // Nothing of the topic's, which lives on, belongs to the request that subscribed first
        $deadline = microtime(true) + 2;
        while ('' === file_get_contents($freed)) {
            expect(microtime(true))->toBeLessThan($deadline, 'the first subscriber\'s request was never freed');
            usleep(20_000);
        }
        expect(services_get($addr, '/publish?m=two'))->toBe('published')
            ->and(native_read_response($second)['body'] ?? null)->toBe('onetwo');
    } finally {
        native_stop($process);
    }
});

test('Swerve::accept() inside a request fails that request with a 500, and the worker goes on', function () {
    [$process, $addr, $log] = swerve_start(workers: 1, fixture: 'services.php');
    try {
        $conn = native_connect($addr);
        fwrite($conn, "GET /accept HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
        expect(native_read_response($conn)['status'])->toBe(500)
            ->and(services_get($addr, '/'))->toBe('hello');
        log_wait($log, '/is called by the worker\'s swerve\.php, not inside a request/');
    } finally {
        native_stop($process);
    }
});
