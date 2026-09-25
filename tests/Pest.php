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
