<?php

/*
 * What the examples that need a server use: swerve started on an application file, as
 * `vendor/bin/swerve --http=127.0.0.1:<port> --workers=1 <file>` starts it, and a client.
 * Not an example itself.
 */

/**
 * Start swerve on `$app`, send each request on a connection of its own, all at once, and print
 * each response in turn (its Date header left out, so the output is the same every run). A
 * request without a Connection header gets `Connection: close`, so its response ends.
 *
 * With `$stop`, swerve is sent `$stop` (SIGTERM: a shutdown, SIGHUP: a reload) once every
 * response has begun, and the responses are read to their end after that.
 */
function serve(string $app, array $requests, ?int $stop = null): void
{
    [$swerve, $addr] = swerve_start($app);
    try {
        $connections = [];
        foreach ($requests as $request) {
            if (!\preg_match('/^connection:/im', $request)) {
                $request = \preg_replace('/\r\n\r\n/', "\r\nConnection: close\r\n\r\n", $request, 1);
            }
            $connection = \stream_socket_client("tcp://$addr", $errno, $error, 5);
            \stream_set_timeout($connection, 10);
            \fwrite($connection, $request);
            $connections[] = $connection;
        }
        $responses = [];
        if (null !== $stop) {
            foreach ($connections as $i => $connection) {
                $responses[$i] = \fread($connection, 65536);
            }
            \proc_terminate($swerve, $stop);
            $stopping = \SIGHUP !== $stop;   // a reload leaves swerve running
        }
        foreach ($connections as $i => $connection) {
            $response = ($responses[$i] ?? '') . \stream_get_contents($connection);
            \fclose($connection);
            echo $i > 0 ? "\n" : '', \rtrim(\preg_replace('/^Date: .*\r\n/m', '', $response)), "\n";
        }
    } finally {
        swerve_stop($swerve, $stopping ?? false);
    }
}

/**
 * Start swerve on `$app`, open a WebSocket to `$path`, send each of `$messages` as a text
 * message (`>`), and print the head of the `101` and what the server sends (`<`) until it
 * closes the connection. With `$stop`, swerve is sent `$stop` once the first message arrived.
 */
function websocket(string $app, string $path, array $messages, ?int $stop = null): void
{
    [$swerve, $addr] = swerve_start($app);
    try {
        $connection = \stream_socket_client("tcp://$addr", $errno, $error, 5);
        \stream_set_timeout($connection, 10);
        \fwrite($connection, "GET $path HTTP/1.1\r\nHost: example\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $head = '';
        while (!\str_contains($head, "\r\n\r\n")) {
            $head .= \fread($connection, 1);
        }
        echo \rtrim(\preg_replace('/^Date: .*\r\n/m', '', $head)), "\n\n";
        $mask = "\1\2\3\4";
        foreach ($messages as $message) {
            echo "> $message\n";
            \fwrite($connection, "\x81" . \chr(0x80 | \strlen($message)) . $mask . ($message ^ \str_repeat($mask, 32)));
        }
        while (2 === \strlen($b = (string) \fread($connection, 2))) {
            $length  = \ord($b[1]) & 0x7F;
            $length  = 126 === $length ? \unpack('n', \fread($connection, 2))[1] : $length;
            $payload = $length > 0 ? \fread($connection, $length) : '';
            if (null !== $stop && !isset($stopping)) {
                \proc_terminate($swerve, $stop);
                $stopping = \SIGHUP !== $stop;
            }
            match (\ord($b[0]) & 0x0F) {
                1       => print("< $payload\n"),
                2       => print('< (binary) ' . \bin2hex($payload) . "\n"),
                8       => print('< close ' . \unpack('n', $payload)[1] . (\strlen($payload) > 2 ? ' ' . \substr($payload, 2) : '') . "\n"),
                default => null,
            };
            if (8 === (\ord($b[0]) & 0x0F)) {
                \fwrite($connection, "\x88\x82$mask" . (\substr($payload, 0, 2) ^ \substr($mask, 0, 2)));
                break;
            }
        }
        \fclose($connection);
    } finally {
        swerve_stop($swerve, $stopping ?? false);
    }
}

/**
 * Swerve only ever loads `./swerve.php` of its working directory: `$app` is symlinked as
 * `swerve.php` into a fresh temporary directory, which becomes swerve's working directory.
 *
 * @return array{0: resource, 1: string} swerve's process, and the address it serves
 */
function swerve_start(string $app): array
{
    $probe = \stream_socket_server('tcp://127.0.0.1:0');
    $addr  = \stream_socket_get_name($probe, false);
    \fclose($probe);
    $dir = \sys_get_temp_dir() . '/swerve-example-' . \bin2hex(\random_bytes(8));
    \mkdir($dir);
    \symlink($app, "$dir/swerve.php");
    \register_shutdown_function(static function () use ($dir) {
        @\unlink("$dir/swerve.php");
        @\rmdir($dir);
    });
    $swerve = \proc_open(
        [\PHP_BINARY, __DIR__ . '/../bin/swerve.php', "--http=$addr", '--workers=1', '--grace=2', '-q'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        $dir,
    );
    $deadline = \microtime(true) + 10;
    while (!($probe = @\stream_socket_client("tcp://$addr", $errno, $error, 1))) {
        if (\microtime(true) > $deadline || !\proc_get_status($swerve)['running']) {
            throw new RuntimeException("swerve did not start on $app");
        }
        \usleep(10_000);
    }
    \fclose($probe);

    return [$swerve, $addr];
}

/**
 * Stop swerve (a SIGTERM: a shutdown) and wait until it has; with `$stopping`, it was told already.
 *
 * @param resource $swerve
 */
function swerve_stop($swerve, bool $stopping = false): void
{
    if (!$stopping && \proc_get_status($swerve)['running']) {
        \proc_terminate($swerve, \SIGTERM);
    }
    \proc_close($swerve);
}
