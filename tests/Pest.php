<?php

/*
 * Helpers for testing swerve's FastCGI worker from the outside: a FastCGI client written
 * from the specification, independent of swerve's own record code.
 */

const FCGI_BEGIN_REQUEST     = 1;
const FCGI_ABORT_REQUEST     = 2;
const FCGI_END_REQUEST       = 3;
const FCGI_PARAMS            = 4;
const FCGI_STDIN             = 5;
const FCGI_STDOUT            = 6;
const FCGI_STDERR            = 7;
const FCGI_GET_VALUES        = 9;
const FCGI_GET_VALUES_RESULT = 10;

/**
 * Start tests/Fixtures/worker.php on a free port and wait until it accepts connections.
 *
 * @return array{0: resource, 1: string} the process and its address
 */
function fcgi_start_worker(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $addr  = stream_socket_get_name($probe, false);
    fclose($probe);

    $process = proc_open([PHP_BINARY, __DIR__ . '/Fixtures/worker.php', "tcp://$addr"], [1 => ['file', '/dev/null', 'w'], 2 => STDERR], $pipes);
    $deadline = microtime(true) + 10;
    // Connection refused until the worker listens; Pest would report each attempt's warning
    set_error_handler(static fn (): bool => true);
    try {
        while (false === ($conn = stream_socket_client("tcp://$addr", $errno, $errstr, 1))) {
            if (microtime(true) > $deadline || !proc_get_status($process)['running']) {
                throw new RuntimeException("The worker did not start listening on $addr");
            }
            usleep(20000);
        }
    } finally {
        restore_error_handler();
    }
    fclose($conn);

    return [$process, $addr];
}

function fcgi_stop_worker($process): void
{
    proc_terminate($process);
    proc_close($process);
}

/**
 * @return resource a blocking connection with a 5 s timeout
 */
function fcgi_connect(string $addr)
{
    $conn = stream_socket_client("tcp://$addr", $errno, $errstr, 5);
    stream_set_timeout($conn, 5);

    return $conn;
}

function fcgi_record(int $type, int $requestId, string $content = ''): string
{
    return pack('CCnnCx', 1, $type, $requestId, strlen($content), 0) . $content;
}

/**
 * @param array<string, string> $pairs
 */
function fcgi_name_values(array $pairs): string
{
    $encoded = '';
    foreach ($pairs as $name => $value) {
        foreach ([strlen($name), strlen($value)] as $length) {
            $encoded .= $length < 128 ? chr($length) : pack('N', $length | 0x80000000);
        }
        $encoded .= $name . $value;
    }

    return $encoded;
}

/**
 * @return array<string, string>
 */
function fcgi_parse_name_values(string $encoded): array
{
    $pairs  = [];
    $offset = 0;
    $length = static function () use ($encoded, &$offset): int {
        $byte = ord($encoded[$offset]);
        if ($byte < 128) {
            ++$offset;

            return $byte;
        }
        $offset += 4;

        return unpack('N', substr($encoded, $offset - 4, 4))[1] & 0x7FFFFFFF;
    };
    while ($offset < strlen($encoded)) {
        $nameLength    = $length();
        $valueLength   = $length();
        $name          = substr($encoded, $offset, $nameLength);
        $pairs[$name]  = substr($encoded, $offset + $nameLength, $valueLength);
        $offset       += $nameLength + $valueLength;
    }

    return $pairs;
}

/**
 * The records of one complete request: BEGIN_REQUEST, the params and the body.
 *
 * @param array<string, string> $headers HTTP headers, such as ['Content-Type' => 'text/plain']
 */
function fcgi_request(int $requestId, string $method, string $uri, string $body = '', array $headers = [], bool $keepConn = true): string
{
    $params = [
        'REQUEST_METHOD'  => $method,
        'REQUEST_URI'     => $uri,
        'SCRIPT_NAME'     => '/index.php',
        'QUERY_STRING'    => (string) parse_url($uri, PHP_URL_QUERY),
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'CONTENT_LENGTH'  => (string) strlen($body),
    ];
    foreach ($headers as $name => $value) {
        $params['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $records = fcgi_record(FCGI_BEGIN_REQUEST, $requestId, pack('nCx5', 1, $keepConn ? 1 : 0))
        . fcgi_record(FCGI_PARAMS, $requestId, fcgi_name_values($params))
        . fcgi_record(FCGI_PARAMS, $requestId);
    foreach (str_split($body, 65535) as $chunk) {
        if ('' !== $chunk) {
            $records .= fcgi_record(FCGI_STDIN, $requestId, $chunk);
        }
    }

    return $records . fcgi_record(FCGI_STDIN, $requestId);
}

/**
 * Read one record, or null at the end of the connection.
 *
 * @return array{type: int, id: int, content: string}|null
 */
function fcgi_read_record($conn): ?array
{
    $header = fcgi_read_exactly($conn, 8);
    if (null === $header) {
        return null;
    }
    $h       = unpack('Cversion/Ctype/nid/nlength/Cpadding', $header);
    $content = $h['length'] > 0 ? fcgi_read_exactly($conn, $h['length']) : '';
    if ($h['padding'] > 0) {
        fcgi_read_exactly($conn, $h['padding']);
    }

    return ['type' => $h['type'], 'id' => $h['id'], 'content' => $content];
}

function fcgi_read_exactly($conn, int $length): ?string
{
    $data = '';
    while (strlen($data) < $length) {
        $chunk = fread($conn, $length - strlen($data));
        if (false === $chunk || '' === $chunk) {
            if (feof($conn) || stream_get_meta_data($conn)['timed_out']) {
                return '' === $data ? null : throw new RuntimeException('Connection ended inside a record');
            }
            continue;
        }
        $data .= $chunk;
    }

    return $data;
}

/**
 * Read the responses to the given request ids, until each has ended.
 *
 * @param int[] $requestIds
 *
 * @return array<int, array{status: int, headers: array<string, string>, body: string, stderr: string, appStatus: int, protocolStatus: int, stdoutRecords: int, order: int}>
 */
function fcgi_read_responses($conn, array $requestIds): array
{
    $open      = array_flip($requestIds);
    $responses = [];
    $order     = 0;
    foreach ($requestIds as $id) {
        $responses[$id] = ['stdout' => '', 'stderr' => '', 'stdoutRecords' => 0];
    }
    while ($open) {
        $record = fcgi_read_record($conn) ?? throw new RuntimeException('Connection ended with requests ' . implode(', ', array_keys($open)) . ' still open');
        $id     = $record['id'];
        if (!isset($responses[$id])) {
            throw new RuntimeException("Record for unexpected request id $id");
        }
        match ($record['type']) {
            FCGI_STDOUT      => [$responses[$id]['stdout'] .= $record['content'], ++$responses[$id]['stdoutRecords']],
            FCGI_STDERR      => $responses[$id]['stderr'] .= $record['content'],
            FCGI_END_REQUEST => (static function () use (&$responses, &$open, &$order, $id, $record) {
                $end                               = unpack('NappStatus/CprotocolStatus', $record['content']);
                $responses[$id]['appStatus']       = $end['appStatus'];
                $responses[$id]['protocolStatus']  = $end['protocolStatus'];
                $responses[$id]['order']           = $order++;
                unset($open[$id]);
            })(),
            default          => throw new RuntimeException("Unexpected record type {$record['type']} for request $id"),
        };
    }

    foreach ($responses as $id => $response) {
        [$head, $body] = explode("\r\n\r\n", $response['stdout'], 2) + [1 => ''];
        $headers       = [];
        foreach (explode("\r\n", $head) as $line) {
            [$name, $value]                   = explode(':', $line, 2) + [1 => ''];
            $headers[strtolower(trim($name))] = trim($value);
        }
        $responses[$id] += [
            'status'  => (int) ($headers['status'] ?? 200),
            'headers' => $headers,
            'body'    => $body,
        ];
        unset($responses[$id]['stdout']);
    }

    return $responses;
}

/**
 * The body of a GET request, or null when nothing answers.
 */
function http_get(string $addr, string $path): ?string
{
    set_error_handler(static fn (): bool => true);
    try {
        $conn = stream_socket_client("tcp://$addr", $errno, $errstr, 1);
    } finally {
        restore_error_handler();
    }
    if (false === $conn) {
        return null;
    }
    stream_set_timeout($conn, 5);
    fwrite($conn, "GET $path HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
    $response = stream_get_contents($conn);
    fclose($conn);
    if (!str_starts_with((string) $response, 'HTTP/1.1 200')) {
        return null;
    }

    return substr($response, strpos($response, "\r\n\r\n") + 4);
}

/*
 * Helpers for testing swerve --http from the outside, with raw sockets.
 */

/*
 * Every swerve started by a test runs in its own process group (setsid), which is SIGKILLed
 * after the test: that reaches replacement workers and orphans too, and can't hang.
 */
uses()->afterEach(function () {
    foreach ($GLOBALS['swerve_groups'] ?? [] as $pgid) {
        @posix_kill(-$pgid, SIGKILL);
    }
    foreach ($GLOBALS['swerve_temp'] ?? [] as $path) {
        exec('rm -rf ' . escapeshellarg($path));
    }
    $GLOBALS['swerve_groups'] = $GLOBALS['swerve_temp'] = [];
})->in(__DIR__);

/**
 * A new temporary file (or with $dir, directory), removed after the test.
 */
function temp_path(bool $dir = false): string
{
    $path = tempnam(sys_get_temp_dir(), 'swerve-test-');
    if ($dir) {
        unlink($path);
        mkdir($path);
    }
    $GLOBALS['swerve_temp'][] = $path;

    return $path;
}

/**
 * Start bin/swerve.php in a process group of its own, and register the group to be killed
 * after the test. $fixture is a file in tests/Fixtures, or a path when it has a slash.
 *
 * @param string[]              $php  arguments for PHP itself, such as ['-d', 'memory_limit=32M']
 * @param array<string, string> $env  more environment variables
 * @param array<int, mixed>     $out  descriptors for stdout and stderr
 *
 * @return resource the process; its pid is the master's, and the process group's
 */
function swerve_spawn(array $args, string $fixture, array $php = [], array $env = [], array $out = [])
{
    $path    = str_contains($fixture, '/') ? $fixture : __DIR__ . "/Fixtures/$fixture";
    $process = proc_open(
        ['setsid', PHP_BINARY, ...$php, __DIR__ . '/../bin/swerve.php', ...$args, $path],
        $out + [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        null,
        $env + getenv(),
    );
    $GLOBALS['swerve_groups'][] = proc_get_status($process)['pid'];

    return $process;
}

function free_address(): string
{
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $addr  = stream_socket_get_name($probe, false);
    fclose($probe);

    return $addr;
}

/**
 * Start bin/swerve.php --http with two workers (or $workers) serving a fixture, on a free port,
 * and wait until it answers. --grace=2 unless $args has one, so stopping stays fast.
 *
 * @param string[] $args more command line arguments
 *
 * @return array{0: resource, 1: string} the process and its address
 */
function native_start(string $fixture = 'app.php', array $args = [], int $workers = 2, array $php = [], array $env = []): array
{
    $addr = free_address();
    if (!preg_grep('/^--grace=/', $args)) {
        $args[] = '--grace=2';
    }
    $process  = swerve_spawn(["--http=$addr", "--workers=$workers", ...$args], $fixture, $php, $env);
    $deadline = microtime(true) + 10;
    while (null === http_get($addr, '/hello')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("swerve did not start serving on $addr");
        }
        usleep(50000);
    }

    return [$process, $addr];
}

/**
 * Stop swerve with SIGINT, as Ctrl+C does, and wait for it; past the timeout, kill its whole
 * process group. Returns the seconds it took.
 */
function native_stop($process, float $timeout = 10): float
{
    $start = microtime(true);
    $pid   = proc_get_status($process)['pid'];
    posix_kill($pid, SIGINT);
    while (proc_get_status($process)['running']) {
        if (microtime(true) - $start > $timeout) {
            posix_kill(-$pid, SIGKILL);
            break;
        }
        usleep(20000);
    }
    proc_close($process);

    return microtime(true) - $start;
}

/**
 * @return resource a blocking connection with a 5 s timeout
 */
function native_connect(string $addr)
{
    $conn = stream_socket_client("tcp://$addr", $errno, $errstr, 5);
    stream_set_timeout($conn, 5);

    return $conn;
}

/**
 * Read a response head up to the empty line: status and headers (lower-cased names), or null
 * when the connection ended first.
 *
 * @return array{status: int, headers: array<string, string>}|null
 */
function native_read_head($conn): ?array
{
    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $line = fgets($conn);
        if (false === $line) {
            return null;
        }
        $head .= $line;
    }
    $lines   = explode("\r\n", rtrim($head));
    $status  = (int) explode(' ', array_shift($lines))[1];
    $headers = [];
    $cookies = [];
    foreach ($lines as $line) {
        [$name, $value]                   = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
        if ('set-cookie' === strtolower(trim($name))) {
            $cookies[] = trim($value);
        }
    }

    return ['status' => $status, 'headers' => $headers, 'cookies' => $cookies];
}

/**
 * One chunk's data of a chunked body, '' for the last chunk (reading the empty line after
 * it), or null when the connection ended first.
 */
function native_read_chunk($conn): ?string
{
    $line = fgets($conn);
    if (false === $line) {
        return null;
    }
    $size = hexdec(trim($line));
    if (0 === $size) {
        return false === fgets($conn) ? null : '';
    }
    $chunk = '';
    while (strlen($chunk) < $size) {
        $data = fread($conn, $size - strlen($chunk));
        if (false === $data || ('' === $data && feof($conn))) {
            return null;
        }
        $chunk .= $data;
    }
    fgets($conn); // CRLF after the chunk

    return $chunk;
}

/**
 * Read one HTTP response: status, headers (lower-cased names) and body, by Content-Length,
 * chunked, or up to the end of the connection; or null when the connection ended first. A
 * 100 Continue is returned as a response of its own. `complete` is false when the body ended
 * before its Content-Length or its last chunk.
 *
 * @param bool $head the response to a HEAD request, without body
 *
 * @return array{status: int, headers: array<string, string>, body: string, complete: bool}|null
 */
function native_read_response($conn, bool $head = false): ?array
{
    $response = native_read_head($conn);
    if (null === $response) {
        return null;
    }
    $headers  = $response['headers'];
    $body     = '';
    $complete = true;
    if ($head || $response['status'] < 200 || 204 === $response['status'] || 304 === $response['status']) {
        // no body
    } elseif (isset($headers['content-length'])) {
        $length = (int) $headers['content-length'];
        while (strlen($body) < $length) {
            $data = fread($conn, $length - strlen($body));
            if (false === $data || ('' === $data && feof($conn))) {
                $complete = false;
                break;
            }
            $body .= $data;
        }
    } elseif ('chunked' === strtolower($headers['transfer-encoding'] ?? '')) {
        while (true) {
            $chunk = native_read_chunk($conn);
            if (null === $chunk) {
                $complete = false;
                break;
            }
            if ('' === $chunk) {
                break;
            }
            $body .= $chunk;
        }
    } else {
        $body = stream_get_contents($conn);
    }

    return $response + ['body' => $body, 'complete' => $complete];
}

/**
 * The server closed the connection without sending anything more (within the 5 s timeout).
 */
function native_closed($conn): bool
{
    $data = @fread($conn, 1);

    return false === $data || ('' === $data && feof($conn));
}

/**
 * Read from a blocking connection until what arrived contains $needle, it ends, or $timeout
 * seconds passed; returns what arrived.
 */
function read_until($conn, string $needle, float $timeout = 5): string
{
    $data     = '';
    $deadline = microtime(true) + $timeout;
    stream_set_blocking($conn, false);
    try {
        while (!str_contains($data, $needle) && ($left = $deadline - microtime(true)) > 0) {
            $read  = [$conn];
            $write = $except = null;
            if (stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1) * 1_000_000))) {
                $chunk = @fread($conn, 65536);
                if (false === $chunk || ('' === $chunk && feof($conn))) {
                    break;
                }
                $data .= $chunk;
            }
        }
    } finally {
        stream_set_blocking($conn, true);
    }

    return $data;
}

/*
 * A WebSocket client (RFC 6455), minimal and written from the specification: the server side
 * is the fixture's /ws, written on the two streams alone, or /websocket, swerve's WebSocket.
 */

/**
 * Open a WebSocket: the handshake, checking the 101 and its Sec-WebSocket-Accept.
 *
 * @return resource the blocking connection, with a 5 s timeout
 */
function ws_connect(string $addr, string $path = '/ws')
{
    $conn = native_connect($addr);
    $key  = base64_encode(random_bytes(16));
    fwrite($conn, "GET $path HTTP/1.1\r\nHost: test\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $head = native_read_head($conn);
    expect($head['status'] ?? null)->toBe(101);
    expect($head['headers']['sec-websocket-accept'] ?? null)->toBe(base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)));

    return $conn;
}

/**
 * Send one frame, masked as a client must.
 */
function ws_send($conn, int $opcode, string $payload, bool $fin = true): void
{
    $n    = strlen($payload);
    $mask = random_bytes(4);
    $head = chr(($fin ? 0x80 : 0) | $opcode) . match (true) {
        $n < 126   => chr(0x80 | $n),
        $n < 65536 => chr(0x80 | 126) . pack('n', $n),
        default    => chr(0x80 | 127) . pack('J', $n),
    };
    fwrite($conn, $head . $mask . ($payload ^ substr(str_repeat($mask, intdiv($n, 4) + 1), 0, $n)));
}

/**
 * The next frame from the server: [opcode, payload], or null when the connection ended.
 *
 * @return array{0: int, 1: string}|null
 */
function ws_read($conn): ?array
{
    $head = fcgi_read_exactly($conn, 2);
    if (null === $head) {
        return null;
    }
    $length = ord($head[1]) & 0x7F;
    if (126 === $length) {
        $length = unpack('n', fcgi_read_exactly($conn, 2))[1];
    } elseif (127 === $length) {
        $length = unpack('J', fcgi_read_exactly($conn, 8))[1];
    }

    return [ord($head[0]) & 0x0F, $length > 0 ? fcgi_read_exactly($conn, $length) : ''];
}

/**
 * Serve requests in this process with a HttpConnection over a SEQPACKET socket pair, so
 * each of the server's writes arrives as one packet: the packets of the responses, up to the
 * end of the connection. Each of $requests is sent as one packet, and a read shorter than a
 * packet loses the rest of it, so a test sees whether the server reads a packet whole.
 *
 * @param string|string[] $requests
 * @param bool            $close    close the client's sending side after the requests
 *
 * @return string[]
 */
function native_serve_packets(Psr\Http\Server\RequestHandlerInterface $handler, string|array $requests, bool $close = false): array
{
    return phasync::run(function () use ($handler, $requests, $close) {
        [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_SEQPACKET, 0);
        stream_set_blocking($server, false);
        stream_set_blocking($client, false);
        stream_set_read_buffer($client, 0);
        $connection = new Swerve\Http\HttpConnection($server, '127.0.0.1:1', new Swerve\Dispatcher($handler, new Psr\Log\NullLogger()), new Psr\Log\NullLogger());
        phasync::go($connection->serve(...));

        foreach ((array) $requests as $packet) {
            fwrite($client, $packet);
        }
        if ($close) {
            stream_socket_shutdown($client, STREAM_SHUT_WR);
        }
        $packets = [];
        while (true) {
            $packet = fread(phasync::readable($client, 5), 1 << 20);
            if (false === $packet || ('' === $packet && feof($client))) {
                break;
            }
            $packets[] = $packet;
        }
        fclose($client);

        return $packets;
    });
}

/*
 * Helpers for the supervision tests: swerve as operators run it, with a log file, and faults
 * injected through the fixture's routes.
 */

/**
 * Start swerve with a log file at INFO level, --grace=3 and --watchdog=3 (unless $args sets
 * them), and wait until it answers /hello; with $wait false, don't wait.
 *
 * @param 'http'|'fastcgi' $mode
 *
 * @return array{0: resource, 1: string, 2: string, 3: int} the process, its address, the log file, the master's pid
 */
function swerve_start(array $args = [], int $workers = 2, array $php = [], array $env = [], string $mode = 'http', string $fixture = 'app.php', bool $wait = true, ?string $addr = null): array
{
    $addr ??= free_address();
    $log  = temp_path();
    foreach (['--grace=3', '--watchdog=3'] as $default) {
        if (!preg_grep('/^' . strstr($default, '=', true) . '=/', $args)) {
            $args[] = $default;
        }
    }
    $process = swerve_spawn(["--$mode=$addr", "--workers=$workers", "--log=$log", '-vv', ...$args], $fixture, $php, $env);
    $deadline = microtime(true) + 10;
    while ($wait && null === ('http' === $mode ? probe($addr, '/hello') : fcgi_get($addr, '/hello'))) {
        if (microtime(true) > $deadline || !proc_get_status($process)['running']) {
            throw new RuntimeException("swerve did not start serving on $addr:\n" . file_get_contents($log));
        }
        usleep(20000);
    }

    return [$process, $addr, $log, proc_get_status($process)['pid']];
}

function swerve_signal($process, int $signal): void
{
    posix_kill(proc_get_status($process)['pid'], $signal);
}

/**
 * Wait for swerve to exit: its exit code, and how long that took.
 *
 * @return array{0: int, 1: float}
 */
function swerve_wait($process, float $timeout): array
{
    $start = microtime(true);
    while (($status = proc_get_status($process))['running']) {
        if (microtime(true) - $start > $timeout) {
            throw new RuntimeException("swerve did not exit within $timeout s");
        }
        usleep(10000);
    }
    proc_close($process);

    return [$status['exitcode'], microtime(true) - $start];
}

/**
 * Wait until the log has a line matching $regex; returns every match so far. The log may not
 * exist yet, after log rotation.
 *
 * @return array<int, array<int|string, string>>
 */
function log_wait(string $log, string $regex, float $timeout = 5): array
{
    $deadline = microtime(true) + $timeout;
    while (!preg_match_all($regex, is_file($log) ? file_get_contents($log) : '', $matches, PREG_SET_ORDER)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("No $regex in the log within $timeout s:\n" . file_get_contents($log));
        }
        usleep(50000);
    }

    return $matches;
}

function log_count(string $log, string $regex): int
{
    return preg_match_all($regex, (string) file_get_contents($log));
}

/**
 * The body of a GET request with status 200, on a fresh connection, or null when it failed or
 * took longer than $timeout.
 */
function probe(string $addr, string $path, float $timeout = 1.0): ?string
{
    set_error_handler(static fn (): bool => true);
    try {
        $conn = stream_socket_client(str_starts_with($addr, 'unix:') ? 'unix://' . substr($addr, 5) : "tcp://$addr", $errno, $errstr, $timeout);
    } finally {
        restore_error_handler();
    }
    if (false === $conn) {
        return null;
    }
    stream_set_timeout($conn, (int) $timeout, (int) (fmod($timeout, 1) * 1_000_000));
    fwrite($conn, "GET $path HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
    $response = @native_read_response($conn);
    fclose($conn);

    return null !== $response && 200 === $response['status'] && $response['complete'] ? $response['body'] : null;
}

/**
 * A fixture route's JSON answer, from a GET on a fresh connection (null decoded when it failed).
 */
function cache_call(string $addr, string $path): array
{
    return json_decode((string) probe($addr, $path), true);
}

/**
 * The pids of $n different workers, asking /pid on fresh connections.
 *
 * @return int[]
 */
function worker_pids(string $addr, int $n, float $timeout = 5): array
{
    $pids     = [];
    $deadline = microtime(true) + $timeout;
    while (count($pids) < $n) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Saw only ' . count($pids) . " of $n workers");
        }
        if (null !== $pid = probe($addr, '/pid')) {
            $pids[(int) $pid] = (int) $pid;
        }
    }

    return array_values($pids);
}

/**
 * The live processes in a process group.
 *
 * @return int[]
 */
function group_pids(int $pgid): array
{
    $pids = [];
    set_error_handler(static fn (): bool => true); // a process may end while it is read
    foreach (glob('/proc/[0-9]*/stat') as $file) {
        $stat = file_get_contents($file);
        // The fields after the command name, which is in parentheses: state, ppid, pgrp. A
        // zombie has ended; only its parent (for an orphan, init) has yet to reap it.
        $fields = $stat ? explode(' ', substr($stat, strrpos($stat, ')') + 2)) : [];
        if ($fields && 'Z' !== $fields[0] && (int) $fields[2] === $pgid) {
            $pids[] = (int) basename(dirname($file));
        }
    }
    restore_error_handler();

    return $pids;
}

/**
 * Wait until a process group is empty; returns whether it is.
 */
function group_gone(int $pgid, float $timeout = 2): bool
{
    $deadline = microtime(true) + $timeout;
    while (group_pids($pgid)) {
        if (microtime(true) > $deadline) {
            return false;
        }
        usleep(20000);
    }

    return true;
}

/**
 * Connection resets a handover may cause: none when the kernel moves connections queued on a
 * closing listener to another one (net.ipv4.tcp_migrate_req=1), a few otherwise.
 */
function resets_allowed(): int
{
    return '1' === trim((string) @file_get_contents('/proc/sys/net/ipv4/tcp_migrate_req')) ? 0 : 3;
}

/**
 * GET $path over and over for $seconds from $concurrency clients at once, each on a fresh
 * connection (Connection: close), and count the outcomes: `ok` (complete 200), `status5xx`,
 * `truncated` (a head, but not all of the body), `reset` (connected, but not a byte of a
 * response), `refused` (not connected). $at runs [seconds, closure] pairs once the load ran
 * that long. `requests` lists each request's [start, end, outcome, body], in seconds since the
 * start.
 *
 * @param array<int, array{0: float, 1: Closure}> $at
 *
 * @return array{ok: int, status5xx: int, truncated: int, reset: int, refused: int, bodies: array<string, int>, requests: array<int, array{0: float, 1: float, 2: string, 3: string}>}
 */
function http_load(string $addr, string $path, float $seconds, int $concurrency = 8, array $at = []): array
{
    // Refused and reset connections warn; Pest would report each one
    set_error_handler(static fn (): bool => true);
    try {
        return http_load_run($addr, $path, $seconds, $concurrency, $at);
    } finally {
        restore_error_handler();
    }
}

function http_load_run(string $addr, string $path, float $seconds, int $concurrency, array $at): array
{
    $result  = ['ok' => 0, 'status5xx' => 0, 'truncated' => 0, 'reset' => 0, 'refused' => 0, 'bodies' => [], 'requests' => []];
    $clients = array_fill(0, $concurrency, null);
    $start   = microtime(true);
    $finish  = static function (array $client, string $outcome, string $body = '') use (&$result, $start) {
        @fclose($client['socket']);
        ++$result[$outcome];
        if ('ok' === $outcome) {
            $result['bodies'][$body] = ($result['bodies'][$body] ?? 0) + 1;
        }
        $result['requests'][] = [$client['start'], microtime(true) - $start, $outcome, $body];
    };
    while (true) {
        $now = microtime(true) - $start;
        foreach ($at as $i => [$t, $fn]) {
            if ($now >= $t) {
                unset($at[$i]);
                $fn();
            }
        }
        foreach ($clients as $i => $client) {
            if (null === $client && $now < $seconds) {
                $socket = @stream_socket_client("tcp://$addr", $errno, $errstr, 1, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
                if (false === $socket) {
                    ++$result['refused'];
                    continue;
                }
                stream_set_blocking($socket, false);
                $clients[$i] = ['socket' => $socket, 'start' => $now, 'sent' => false, 'data' => ''];
            }
        }
        if (!array_filter($clients)) {
            if ($now >= $seconds) {
                break;
            }
            usleep(1000);
            continue;
        }
        $read = $write = [];
        foreach ($clients as $i => $client) {
            if (null !== $client) {
                $client['sent'] ? $read[$i] = $client['socket'] : $write[$i] = $client['socket'];
            }
        }
        $except = null;
        if (!@stream_select($read, $write, $except, 0, 20000)) {
            foreach ($clients as $i => $client) {
                if (null !== $client && $now - $client['start'] > 10) {
                    $finish($client, 'reset');
                    $clients[$i] = null;
                }
            }
            continue;
        }
        foreach ($write as $i => $socket) {
            if (!@fwrite($socket, "GET $path HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n")) {
                $finish($clients[$i], 'refused'); // the connect failed
                $clients[$i] = null;
                continue;
            }
            $clients[$i]['sent'] = true;
        }
        foreach ($read as $i => $socket) {
            $data = @fread($socket, 65536);
            if (false !== $data && '' !== $data) {
                $clients[$i]['data'] .= $data;
                continue;
            }
            if (false !== $data && !feof($socket)) {
                continue;
            }
            // The end of the response, or of the connection
            $response = $clients[$i]['data'];
            if ('' === $response) {
                $finish($clients[$i], 'reset');
            } elseif (false === $end = strpos($response, "\r\n\r\n")) {
                $finish($clients[$i], 'truncated');
            } else {
                $status = (int) substr($response, 9, 3);
                $body   = substr($response, $end + 4);
                preg_match('/\r\ncontent-length: *(\d+)/i', substr($response, 0, $end), $m);
                if (isset($m[1]) && strlen($body) < (int) $m[1]) {
                    $finish($clients[$i], 'truncated');
                } else {
                    $finish($clients[$i], $status >= 500 ? 'status5xx' : (200 === $status ? 'ok' : 'truncated'), $body);
                }
            }
            $clients[$i] = null;
        }
    }

    return $result;
}

/**
 * The response to a GET over FastCGI on a fresh connection, or null when it failed or took
 * longer than $timeout.
 *
 * @return array{status: int, headers: array<string, string>, body: string}|null
 */
function fcgi_get(string $addr, string $path, float $timeout = 1.0): ?array
{
    set_error_handler(static fn (): bool => true);
    try {
        $conn = stream_socket_client(str_starts_with($addr, 'unix:') ? 'unix://' . substr($addr, 5) : "tcp://$addr", $errno, $errstr, $timeout);
        if (false === $conn) {
            return null;
        }
        stream_set_timeout($conn, (int) ceil($timeout));
        fwrite($conn, fcgi_request(1, 'GET', $path, keepConn: false));

        return fcgi_read_responses($conn, [1])[1];
    } catch (RuntimeException) {
        return null;
    } finally {
        restore_error_handler();
        if ($conn) {
            fclose($conn);
        }
    }
}

/**
 * What arrives on a non-blocking connection within $timeout seconds, until its end: [data,
 * whether the connection was reset instead of ending cleanly].
 *
 * @return array{0: string, 1: bool}
 */
function read_to_end($conn, float $timeout = 5): array
{
    $data     = '';
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $r = [$conn];
        $w = $e = null;
        if (stream_select($r, $w, $e, 0, 100_000)) {
            $chunk = @fread($conn, 65536);
            if (false === $chunk) {
                return [$data, true];
            }
            if ('' === $chunk && feof($conn)) {
                return [$data, false];
            }
            $data .= $chunk;
        }
    }

    return [$data, false];
}
