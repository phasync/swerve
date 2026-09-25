<?php

use Swerve\HAProxy;

/*
 * swerve in web server mode, started as a user would: bin/swerve.php --http, with HAProxy
 * in front of the workers passing requests over multiplexed FastCGI.
 */

beforeEach(function () {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $this->addr = stream_socket_get_name($probe, false);
    fclose($probe);

    $this->master = proc_open(
        [PHP_BINARY, __DIR__ . '/../bin/swerve.php', "--http={$this->addr}", '--workers=2', __DIR__ . '/Fixtures/app.php'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );
    $this->socketDir = sys_get_temp_dir() . '/swerve-' . proc_get_status($this->master)['pid'];

    $deadline = microtime(true) + 10;
    while (null === http_get($this->addr, '/hello')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('swerve did not start serving on ' . $this->addr);
        }
        usleep(50000);
    }
})->skip(null === HAProxy::findBinary(), 'needs haproxy');

afterEach(function () {
    if (isset($this->master)) {
        proc_terminate($this->master, SIGINT);
        proc_close($this->master);
    }
});

test('it serves HTTP through HAProxy', function () {
    expect(http_get($this->addr, '/hello'))->toBe('Hello');
});

test('concurrent requests share one multiplexed FastCGI connection per worker', function () {
    $requests = [];
    for ($i = 1; $i <= 40; ++$i) {
        $requests[$i] = stream_socket_client("tcp://{$this->addr}");
        fwrite($requests[$i], "GET /sleep?ms=400&id=$i HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
    }
    $start = microtime(true);
    usleep(200000); // all 40 are now waiting in the application

    // Each worker slot has its own socket; count the connections HAProxy made to them
    $connections = substr_count(shell_exec('ss -Hx state connected') ?? '', "{$this->socketDir}/worker");

    $bodies = [];
    foreach ($requests as $i => $conn) {
        $response   = stream_get_contents($conn);
        $bodies[$i] = substr($response, strpos($response, "\r\n\r\n") + 4);
    }
    $elapsed = microtime(true) - $start;

    expect($bodies)->toBe(array_combine(range(1, 40), array_map(static fn ($i) => "slept $i", range(1, 40))));
    expect($connections)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(2); // not one per request
    expect($elapsed)->toBeLessThan(1.0);           // concurrent, not 40 x 0.4 s
})->skip(!is_executable('/usr/bin/ss') && !is_executable('/bin/ss'), 'needs ss');
