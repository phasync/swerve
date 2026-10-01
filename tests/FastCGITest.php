<?php

/*
 * swerve's FastCGI worker, tested from the outside with raw FastCGI records, as a web server such as HAProxy or nginx
 * talks to it: several requests multiplexed on one connection, kept open between requests.
 */

beforeEach(function () {
    [$this->worker, $this->addr] = fcgi_start_worker();
});

afterEach(function () {
    fcgi_stop_worker($this->worker);
});

test('a GET request gets its response and ends with REQUEST_COMPLETE', function () {
    $conn = fcgi_connect($this->addr);
    fwrite($conn, fcgi_request(1, 'GET', '/hello'));
    $response = fcgi_read_responses($conn, [1])[1];

    expect($response['status'])->toBe(200);
    expect($response['headers']['content-type'])->toBe('text/plain');
    expect($response['body'])->toBe('Hello');
    expect([$response['appStatus'], $response['protocolStatus']])->toBe([0, 0]);
});

test('a POST body sent as FCGI_STDIN reaches the application', function () {
    $conn = fcgi_connect($this->addr);
    fwrite($conn, fcgi_request(1, 'POST', '/echo', 'posted data', ['Content-Type' => 'text/plain']));

    expect(fcgi_read_responses($conn, [1])[1]['body'])->toBe('posted data');
});

test('a POST body larger than one FastCGI record reaches the application whole', function () {
    $body = str_repeat('0123456789', 20000); // 200 000 bytes, four STDIN records
    $conn = fcgi_connect($this->addr);
    fwrite($conn, fcgi_request(1, 'POST', '/echo', $body));

    expect(fcgi_read_responses($conn, [1])[1]['body'])->toBe($body);
});

test('the method, request target and HTTP headers from FCGI_PARAMS reach the application', function () {
    $conn = fcgi_connect($this->addr);
    fwrite($conn, fcgi_request(1, 'PUT', '/params?a=1', '', ['X-Custom' => 'yes', 'Accept' => 'text/plain']));
    $seen = json_decode(fcgi_read_responses($conn, [1])[1]['body'], true);

    expect($seen['method'])->toBe('PUT');
    expect($seen['target'])->toBe('/params?a=1');
    expect($seen['headers']['x-custom'] ?? null)->toBe('yes');
    expect($seen['headers']['accept'] ?? null)->toBe('text/plain');
});

test('several requests multiplexed on one connection are handled at the same time', function () {
    $conn  = fcgi_connect($this->addr);
    $start = microtime(true);
    // Sent in one write, as HAProxy does when it has several requests for the connection
    fwrite($conn, fcgi_request(1, 'GET', '/sleep?ms=300&id=1')
        . fcgi_request(2, 'GET', '/sleep?ms=100&id=2')
        . fcgi_request(3, 'GET', '/sleep?ms=200&id=3'));
    $responses = fcgi_read_responses($conn, [1, 2, 3]);
    $elapsed   = microtime(true) - $start;

    expect(array_map(static fn ($r) => $r['body'], $responses))->toBe([1 => 'slept 1', 2 => 'slept 2', 3 => 'slept 3']);
    // Each finishes when its own work is done, not in the order they were sent
    expect(array_map(static fn ($r) => $r['order'], $responses))->toBe([1 => 2, 2 => 0, 3 => 1]);
    expect($elapsed)->toBeLessThan(0.5); // one after another would take 0.6 s
});

test('records of different requests may be interleaved on one connection', function () {
    $conn = fcgi_connect($this->addr);
    // Alternate the two requests' records, one record from each in turn
    $a = fcgi_request(1, 'POST', '/echo', 'first body');
    $b = fcgi_request(2, 'POST', '/echo', 'second body');
    fwrite($conn, fcgi_interleave($a, $b));
    $responses = fcgi_read_responses($conn, [1, 2]);

    expect([$responses[1]['body'], $responses[2]['body']])->toBe(['first body', 'second body']);
});

test('a connection with FCGI_KEEP_CONN serves one request after another', function () {
    $conn = fcgi_connect($this->addr);
    foreach ([1, 2, 3] as $id) {
        fwrite($conn, fcgi_request($id, 'GET', '/hello'));
        expect(fcgi_read_responses($conn, [$id])[$id]['body'])->toBe('Hello');
    }
});

test('without FCGI_KEEP_CONN the worker closes the connection after the response', function () {
    $conn = fcgi_connect($this->addr);
    fwrite($conn, fcgi_request(1, 'GET', '/hello', keepConn: false));
    expect(fcgi_read_responses($conn, [1])[1]['body'])->toBe('Hello');

    expect(fcgi_read_record($conn))->toBeNull(); // the connection has ended
});

test('FCGI_GET_VALUES reports that requests may be multiplexed', function () {
    $conn = fcgi_connect($this->addr);
    fwrite($conn, fcgi_record(FCGI_GET_VALUES, 0, fcgi_name_values(['FCGI_MPXS_CONNS' => '', 'FCGI_MAX_REQS' => ''])));
    $record = fcgi_read_record($conn);

    expect($record['type'])->toBe(FCGI_GET_VALUES_RESULT);
    expect(fcgi_parse_name_values($record['content'])['FCGI_MPXS_CONNS'] ?? null)->toBe('1');
});

test('a response body larger than one FastCGI record arrives whole', function () {
    $conn = fcgi_connect($this->addr);
    fwrite($conn, fcgi_request(1, 'GET', '/big?n=200000'));
    $response = fcgi_read_responses($conn, [1])[1];

    expect(strlen($response['body']))->toBe(200000);
    expect($response['body'])->toBe(str_repeat('x', 200000));
});

/**
 * Interleave the records of two record streams, one record from each in turn.
 */
function fcgi_interleave(string $a, string $b): string
{
    $split = static function (string $records): array {
        $out = [];
        while ('' !== $records) {
            $h      = unpack('Cversion/Ctype/nid/nlength/Cpadding', $records);
            $size   = 8 + $h['length'] + $h['padding'];
            $out[]  = substr($records, 0, $size);
            $records = substr($records, $size);
        }

        return $out;
    };
    $a   = $split($a);
    $b   = $split($b);
    $out = '';
    for ($i = 0; $i < max(count($a), count($b)); ++$i) {
        $out .= ($a[$i] ?? '') . ($b[$i] ?? '');
    }

    return $out;
}

test('a response written in chunks larger than one FastCGI record is split into several records', function () {
    // The body is echoed, and the application writes it in 64 KB chunks: one byte more than
    // a record can hold. Whether a chunk is that large depends on how much has arrived, so
    // try sizes around the limit.
    $conn = fcgi_connect($this->addr);
    foreach ([65535, 65536, 65537, 131072, 200000] as $id => $size) {
        $body = substr(str_repeat('0123456789abcdef', intdiv($size, 16) + 1), 0, $size);
        fwrite($conn, fcgi_request($id + 1, 'POST', '/echo', $body));
        expect(fcgi_read_responses($conn, [$id + 1])[$id + 1]['body'])->toBe($body);
    }
});

test('an application that throws gets a 500, and the connection serves the next request', function () {
    $conn = fcgi_connect($this->addr);
    fwrite($conn, fcgi_request(1, 'GET', '/throw'));
    $response = fcgi_read_responses($conn, [1])[1];
    expect($response['status'])->toBe(500);
    expect($response['body'])->toBe('Internal Server Error');
    expect([$response['appStatus'], $response['protocolStatus']])->toBe([0, 0]);

    fwrite($conn, fcgi_request(2, 'GET', '/hello'));
    expect(fcgi_read_responses($conn, [2])[2]['body'])->toBe('Hello');
});

test('a protocol upgrade is answered with 501: no web server carries a 101 over FastCGI', function () {
    $conn = fcgi_connect($this->addr);
    fwrite($conn, fcgi_request(1, 'GET', '/websocket', '', [
        'Upgrade'               => 'websocket',
        'Connection'            => 'Upgrade',
        'Sec-WebSocket-Key'     => base64_encode(random_bytes(16)),
        'Sec-WebSocket-Version' => '13',
    ]));
    $response = fcgi_read_responses($conn, [1])[1];
    expect($response['status'])->toBe(501);
    expect($response['body'])->toContain('HTTP mode');

    fwrite($conn, fcgi_request(2, 'GET', '/hello'));
    expect(fcgi_read_responses($conn, [2])[2]['body'])->toBe('Hello');
});
