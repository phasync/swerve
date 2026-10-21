<?php

/*
 * Classic mode: swerve forwarding to a pool of php -S processes (see src/Classic).
 */

/**
 * Starts swerve in --classic mode serving $docroot/$router, waits until its TCP port answers,
 * and returns [process, address].
 *
 * @return array{0: resource, 1: string}
 */
function classic_start(string $docroot, string $router, int $pool = 4, array $args = []): array
{
    $addr    = free_address();
    $process = swerve_spawn(['--classic', '-t', $docroot, "--classic-pool=$pool", "--http=$addr", '--grace=2', ...$args], "$docroot/$router");
    $deadline = microtime(true) + 10;
    while (true) {
        $probe = @stream_socket_client("tcp://$addr", $errno, $errstr, 0.2);
        if (false !== $probe) {
            fclose($probe);

            break;
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException("swerve --classic did not start serving on $addr");
        }
        usleep(50000);
    }

    return [$process, $addr];
}

test('a GET is answered by the front controller, with the client identity passed through and spoofed copies stripped', function () {
    $docroot = temp_path(true);
    file_put_contents("$docroot/index.php", <<<'PHP'
        <?php
        echo json_encode([
            'remote' => $_SERVER['REMOTE_ADDR'] ?? null,
            'host'   => $_SERVER['HTTP_HOST'] ?? null,
            'spoof'  => $_SERVER['HTTP_X_SWERVE_REMOTE_ADDR'] ?? 'not set',
        ]);
        PHP);
    [$process, $addr] = classic_start($docroot, 'index.php');
    try {
        $conn = native_connect($addr);
        fwrite($conn, "GET /index.php HTTP/1.1\r\nHost: example.test\r\nX-Swerve-Remote-Addr: 9.9.9.9\r\nConnection: close\r\n\r\n");
        $response = native_read_response($conn);
        expect($response['status'])->toBe(200);
        $body = json_decode($response['body'], true);
        expect($body['remote'])->toBe('127.0.0.1');   // not the spoofed 9.9.9.9
        expect($body['host'])->toBe('example.test');
        expect($body['spoof'])->toBe('not set');       // the prepend script removed it
    } finally {
        native_stop($process);
    }
});

test('a second request on the same kept-alive connection is answered too', function () {
    $docroot = temp_path(true);
    file_put_contents("$docroot/index.php", '<?php echo "ok";');
    [$process, $addr] = classic_start($docroot, 'index.php');
    try {
        $conn = native_connect($addr);
        for ($i = 1; $i <= 3; ++$i) {
            fwrite($conn, "GET /index.php HTTP/1.1\r\nHost: test\r\nConnection: keep-alive\r\n\r\n");
            $response = native_read_response($conn);
            expect($response)->not->toBeNull("request $i got no response (connection reuse hung or closed)");
            expect($response['status'])->toBe(200);
            expect($response['body'])->toBe('ok');
        }
    } finally {
        native_stop($process);
    }
});

test('a flushed echo reaches the client before a later one that follows a sleep', function () {
    $docroot = temp_path(true);
    file_put_contents("$docroot/index.php", <<<'PHP'
        <?php
        echo "first-part";
        flush();
        usleep(200000);
        echo "second-part";
        PHP);
    [$process, $addr] = classic_start($docroot, 'index.php');
    try {
        $conn = native_connect($addr);
        fwrite($conn, "GET /index.php HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
        $head = native_read_head($conn);
        expect($head['status'])->toBe(200);
        // The first chunk (or the start of a content-length body) must arrive well under the
        // 200 ms the script sleeps between its two echoes: a proxy that buffers the whole
        // response before sending anything would make this wait close to 200 ms instead.
        $start = microtime(true);
        if (isset($head['headers']['content-length'])) {
            $first = fread($conn, 10);
        } else {
            $first = native_read_chunk($conn);
        }
        $elapsed = microtime(true) - $start;
        expect($first)->toBe('first-part');
        expect($elapsed)->toBeLessThan(0.1);
    } finally {
        native_stop($process);
    }
});
