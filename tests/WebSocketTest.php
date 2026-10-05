<?php

/*
 * Swerve\WebSocket, the contract of docs/websocket.md, tested from the outside with raw sockets
 * against tests/Fixtures/realtime.php: once without and once with phasync-ext, and nothing may differ.
 *
 * Nothing is slept for: a test waits for the condition it needs, with a generous deadline.
 */

/**
 * The bytes of a frame: $byte0 is FIN, RSV and the opcode; $bits (7, 16 or 64) forces how the length is written.
 */
function ws_bytes(int $byte0, string $payload, bool $masked = true, ?int $bits = null): string
{
    $n     = strlen($payload);
    $bits ??= $n < 126 ? 7 : ($n < 65536 ? 16 : 64);
    $m     = $masked ? 0x80 : 0;
    $mask  = "\x37\xfa\x21\x3d";
    $len   = match ($bits) {
        7  => chr($m | $n),
        16 => chr($m | 126) . pack('n', $n),
        64 => chr($m | 127) . pack('J', $n),
    };

    return chr($byte0) . $len . ($masked ? $mask . ($payload ^ substr(str_repeat($mask, intdiv($n, 4) + 1), 0, $n)) : $payload);
}

function ws_raw($conn, int $byte0, string $payload, bool $masked = true, ?int $bits = null): void
{
    fwrite($conn, ws_bytes($byte0, $payload, $masked, $bits));
}

/**
 * The next frame from the server with everything a client checks, or null at the end of the connection.
 *
 * @return array{fin: bool, rsv: int, op: int, masked: bool, payload: string}|null
 */
function ws_frame($conn): ?array
{
    $head = read_exactly($conn, 2);
    if (null === $head) {
        return null;
    }
    $length = ord($head[1]) & 0x7F;
    if (126 === $length) {
        $length = unpack('n', read_exactly($conn, 2))[1];
    } elseif (127 === $length) {
        $length = unpack('J', read_exactly($conn, 8))[1];
    }

    return [
        'fin'     => (bool) (ord($head[0]) & 0x80),
        'rsv'     => (ord($head[0]) >> 4) & 7,
        'op'      => ord($head[0]) & 0x0F,
        'masked'  => (bool) (ord($head[1]) & 0x80),
        'payload' => $length > 0 ? read_exactly($conn, $length) : '',
    ];
}

/** The server ended the connection cleanly: a FIN, not a timeout. */
function ws_end($conn): bool
{
    $data = @fread($conn, 1);

    return '' === $data && feof($conn) && !stream_get_meta_data($conn)['timed_out'];
}

/** The server closes with $code (and $reason) and then ends the connection. */
function ws_expect_close($conn, int $code, string $reason = ''): void
{
    expect(ws_read($conn))->toBe([8, pack('n', $code) . $reason]);
    expect(ws_end($conn))->toBeTrue();
}

/**
 * A handshake request, with $headers replacing the usual ones or (null) removing them; a list value is sent as several lines.
 *
 * @param array<string, string|list<string>|null> $headers
 *
 * @return array{0: resource, 1: array{status: int, headers: array<string, string>}} the connection, and the response head
 */
function ws_handshake(string $addr, string $path = '/websocket', array $headers = [], string $method = 'GET', string $version = '1.1', string $body = ''): array
{
    $headers += [
        'Host'                  => 'test',
        'Upgrade'               => 'websocket',
        'Connection'            => 'Upgrade',
        'Sec-WebSocket-Key'     => base64_encode(random_bytes(16)),
        'Sec-WebSocket-Version' => '13',
    ];
    $conn = native_connect($addr);
    $head = "$method $path HTTP/$version\r\n";
    foreach ($headers as $name => $values) {
        foreach ((array) $values as $value) {
            $head .= "$name: $value\r\n";
        }
    }
    fwrite($conn, "$head\r\n$body");

    return [$conn, native_read_head($conn)];
}

test('text and binary are echoed, fragments joined, a ping answered, a close returned; server frames are final, unmasked, without reserved bits', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $conn = ws_connect($addr, '/websocket');
        ws_send($conn, 1, 'hello');
        expect(ws_frame($conn))->toBe(['fin' => true, 'rsv' => 0, 'op' => 1, 'masked' => false, 'payload' => 'hello']);
        ws_send($conn, 2, "\x00\xFF");
        expect(ws_frame($conn))->toBe(['fin' => true, 'rsv' => 0, 'op' => 2, 'masked' => false, 'payload' => "\x00\xFF"]);
        ws_send($conn, 1, '');
        expect(ws_read($conn))->toBe([1, '']);
        ws_send($conn, 1, 'frag', false);
        ws_send($conn, 9, 'are you there');   // a ping between fragments
        ws_send($conn, 0, 'mented', false);
        ws_send($conn, 0, ' ✓');
        expect(ws_read($conn))->toBe([10, 'are you there']);
        expect(ws_read($conn))->toBe([1, 'fragmented ✓']);
        $large = str_repeat('0123456789', 70_000); // 700 kB: a 64-bit length
        ws_send($conn, 1, $large);
        expect(ws_read($conn) === [1, $large])->toBeTrue();
        ws_send($conn, 8, pack('n', 1000) . 'done');
        ws_expect_close($conn, 1000);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('the callback returning closes with 1000; throwing closes with 1011 and is logged', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $conn = ws_connect($addr, '/websocket');
        ws_send($conn, 1, 'bye');
        expect(ws_read($conn))->toBe([8, pack('n', 1000)]);
        ws_send($conn, 8, pack('n', 1000));
        expect(ws_end($conn))->toBeTrue();

        $conn = ws_connect($addr, '/websocket');
        ws_send($conn, 1, 'throw');
        ws_expect_close($conn, 1011);
        log_wait($log, '/the WebSocket callback failed/');
        expect(probe($addr, '/hello'))->toBe('Hello');
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(CRITICAL|Unhandled|Warning:)/'))->toBe(0, file_get_contents($log));
})->with('modes');

test('a client breaking the protocol is closed with 1002, 1007 or 1009', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    $cases = [
        'unmasked'                              => [fn ($c) => ws_raw($c, 0x81, 'hi', false), 1002],
        'a reserved bit (RSV1)'                 => [fn ($c) => ws_raw($c, 0xC1, 'hi'), 1002],
        'a reserved bit (RSV2)'                 => [fn ($c) => ws_raw($c, 0xA1, 'hi'), 1002],
        'a reserved bit (RSV3)'                 => [fn ($c) => ws_raw($c, 0x91, 'hi'), 1002],
        'a reserved data opcode'                => [fn ($c) => ws_raw($c, 0x83, 'hi'), 1002],
        'a reserved control opcode'             => [fn ($c) => ws_raw($c, 0x8B, ''), 1002],
        'a continuation of nothing'             => [fn ($c) => ws_send($c, 0, 'hi'), 1002],
        'a new message mid-message'             => [function ($c) {
            ws_send($c, 1, 'a', false);
            ws_send($c, 1, 'b');
        }, 1002],
        'a fragmented ping'                     => [fn ($c) => ws_send($c, 9, 'hi', false), 1002],
        'a fragmented close'                    => [fn ($c) => ws_send($c, 8, pack('n', 1000), false), 1002],
        'a ping of 126 bytes'                   => [fn ($c) => ws_send($c, 9, str_repeat('p', 126)), 1002],
        'a length with its top bit'             => [fn ($c) => fwrite($c, "\x81\xFF" . pack('J', PHP_INT_MIN | 5) . "\x01\x02\x03\x04"), 1002],
        'text that is not UTF-8'                => [fn ($c) => ws_send($c, 1, "\xC3\x28"), 1007],
        'text cut inside a character'           => [fn ($c) => ws_send($c, 1, "ok\xE2\x9C"), 1007],
        'a surrogate in text'                   => [fn ($c) => ws_send($c, 1, "\xED\xA0\x80"), 1007],
        'an overlong encoding'                  => [fn ($c) => ws_send($c, 1, "\xC0\x80"), 1007],
        'fragments that are not UTF-8 together' => [function ($c) {
            ws_send($c, 1, "\xE2\x9C", false);
            ws_send($c, 0, "\x28");
        }, 1007],
        'a frame too large'                     => [fn ($c) => fwrite($c, "\x81\xFF" . pack('J', (1 << 20) + 1) . "\x01\x02\x03\x04"), 1009],
        'fragments too large together'          => [function ($c) {
            ws_send($c, 2, str_repeat('a', 700_000), false);
            ws_send($c, 0, str_repeat('b', 400_000));
        }, 1009],
    ];
    try {
        foreach ($cases as $name => [$send, $code]) {
            $conn = ws_connect($addr, '/websocket');
            $send($conn);
            expect(ws_read($conn))->toBe([8, pack('n', $code)], $name);
            expect(ws_end($conn))->toBeTrue($name);
            fclose($conn);
        }
        expect(probe($addr, '/hello'))->toBe('Hello');
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('a message above the limit given to serve() closes with 1009, one at the limit goes through', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $conn = ws_connect($addr, '/websocket-limit');
        ws_send($conn, 1, str_repeat('a', 1000));
        expect(ws_read($conn))->toBe([1, '1000 bytes']);
        ws_send($conn, 1, str_repeat('a', 1001));
        ws_expect_close($conn, 1009);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('handshake: what is accepted, what is answered 400 (naming version 13) and what 426 (naming websocket)', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    $key = base64_encode(random_bytes(16));
    // [headers, method, version, status]
    $cases = [
        'a plain handshake'                    => [[], 'GET', '1.1', 101],
        'Upgrade in capitals'                  => [['Upgrade' => 'WebSocket'], 'GET', '1.1', 101],
        'Connection in capitals'               => [['Connection' => 'UPGRADE'], 'GET', '1.1', 101],
        'Connection with several tokens'       => [['Connection' => 'keep-alive, Upgrade'], 'GET', '1.1', 101],
        'Connection tokens on several lines'   => [['Connection' => ['keep-alive', 'upgrade']], 'GET', '1.1', 101],
        'Upgrade with several protocols'       => [['Upgrade' => 'h2c, websocket'], 'GET', '1.1', 101],
        'an extension offered'                 => [['Sec-WebSocket-Extensions' => 'permessage-deflate; client_max_window_bits'], 'GET', '1.1', 101],

        'no key'                               => [['Sec-WebSocket-Key' => null], 'GET', '1.1', 400],
        'an empty key'                         => [['Sec-WebSocket-Key' => ''], 'GET', '1.1', 400],
        'a key that is not base64'             => [['Sec-WebSocket-Key' => '!!!!!!!!!!!!!!!!!!!!!!=='], 'GET', '1.1', 400],
        'a key of 15 bytes'                    => [['Sec-WebSocket-Key' => base64_encode(random_bytes(15))], 'GET', '1.1', 400],
        'a key of 17 bytes'                    => [['Sec-WebSocket-Key' => base64_encode(random_bytes(17))], 'GET', '1.1', 400],
        'two keys'                             => [['Sec-WebSocket-Key' => [$key, base64_encode(random_bytes(16))]], 'GET', '1.1', 400],
        'version 8'                            => [['Sec-WebSocket-Version' => '8'], 'GET', '1.1', 400],
        'no version'                           => [['Sec-WebSocket-Version' => null], 'GET', '1.1', 400],
        'versions 13 and 8'                    => [['Sec-WebSocket-Version' => '13, 8'], 'GET', '1.1', 400],
        'POST'                                 => [[], 'POST', '1.1', 400],
        'HEAD'                                 => [[], 'HEAD', '1.1', 400],
        'HTTP/1.0'                             => [[], 'GET', '1.0', 400],

        'no Upgrade'                           => [['Upgrade' => null], 'GET', '1.1', 426],
        'another Upgrade'                      => [['Upgrade' => 'h2c'], 'GET', '1.1', 426],
        'a token that only contains websocket' => [['Upgrade' => 'websockets'], 'GET', '1.1', 426],
        'no Connection'                        => [['Connection' => null], 'GET', '1.1', 426],
        'Connection without upgrade'           => [['Connection' => 'keep-alive'], 'GET', '1.1', 426],
        'neither'                              => [['Upgrade' => null, 'Connection' => null], 'GET', '1.1', 426],
    ];
    try {
        foreach (['/websocket', '/websocket-accept'] as $path) {
            foreach ($cases as $name => [$headers, $method, $version, $status]) {
                $sent          = $headers['Sec-WebSocket-Key'] ?? $key;
                [$conn, $head] = ws_handshake($addr, $path, $headers + ['Sec-WebSocket-Key' => $key], $method, $version);
                expect($head['status'] ?? null)->toBe($status, "$path $name");
                if (101 === $status) {
                    expect($head['headers']['sec-websocket-accept'])->toBe(base64_encode(sha1($sent . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)), $name);
                    expect($head['headers']['upgrade'])->toBe('websocket', $name);
                    expect(strtolower($head['headers']['connection']))->toBe('upgrade', $name);
                    expect($head['headers'])->not->toHaveKey('sec-websocket-extensions');
                    expect($head['headers'])->not->toHaveKey('sec-websocket-protocol');
                } elseif (400 === $status) {
                    expect($head['headers']['sec-websocket-version'] ?? null)->toBe('13', "$path $name");
                } else {
                    expect(strtolower($head['headers']['upgrade'] ?? ''))->toBe('websocket', "$path $name");
                }
                fclose($conn);
            }
        }

        // The RFC's own example: this key must be answered with this accept
        [$conn, $head] = ws_handshake($addr, '/websocket', ['Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==']);
        expect($head['headers']['sec-websocket-accept'])->toBe('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=');

        // A refusal is an ordinary response: the connection serves the next request
        $conn = native_connect($addr);
        fwrite($conn, "GET /websocket HTTP/1.1\r\nHost: test\r\n\r\nGET /hello HTTP/1.1\r\nHost: test\r\n\r\n");
        expect(native_read_response($conn)['status'])->toBe(426);
        expect(native_read_response($conn)['body'])->toBe('Hello');

        // A handshake with a body is no handshake
        [$conn, $head] = ws_handshake($addr, '/websocket', ['Content-Length' => '5'], body: 'hello');
        expect($head['status'])->toBe(400);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('subprotocols: the first the server prefers among the client\'s offers is chosen, none when they share none', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    $cases = [
        'the server\'s preference wins' => [['Sec-WebSocket-Protocol' => 'chat.v1, chat.v2'], 'chat.v2'],
        'offers on several lines'       => [['Sec-WebSocket-Protocol' => ['foo', 'chat.v1']], 'chat.v1'],
        'only one in common'            => [['Sec-WebSocket-Protocol' => 'foo,chat.v1,bar'], 'chat.v1'],
        'nothing in common'             => [['Sec-WebSocket-Protocol' => 'foo, bar'], null],
        'names are case-sensitive'      => [['Sec-WebSocket-Protocol' => 'CHAT.V2'], null],
        'no offer'                      => [[], null],
    ];
    try {
        foreach ($cases as $name => [$headers, $chosen]) {
            [$conn, $head] = ws_handshake($addr, '/websocket-sub', $headers);
            expect($head['status'])->toBe(101, $name);
            expect($head['headers']['sec-websocket-protocol'] ?? null)->toBe($chosen, $name);
            expect(ws_read($conn))->toBe([1, $chosen ?? 'none'], $name);
            fclose($conn);
        }
        // Not asked for by the server: none is chosen
        [$conn, $head] = ws_handshake($addr, '/websocket', ['Sec-WebSocket-Protocol' => 'chat.v2']);
        expect($head['headers'])->not->toHaveKey('sec-websocket-protocol');
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('an origin allow-list refuses a browser page of another origin with 403, and lets through a client that sends none', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        foreach (['https://example.com', 'https://www.example.com', 'HTTPS://Example.COM'] as $origin) {
            [$conn, $head] = ws_handshake($addr, '/websocket-origin', ['Origin' => $origin]);
            expect($head['status'])->toBe(101, $origin);
            expect(ws_read($conn))->toBe([1, 'welcome']);
        }
        [$conn, $head] = ws_handshake($addr, '/websocket-origin');
        expect($head['status'])->toBe(101);
        foreach (['https://evil.example', 'http://example.com', 'https://example.com.evil.example', 'null', ''] as $origin) {
            [$conn, $head] = ws_handshake($addr, '/websocket-origin', ['Origin' => $origin]);
            expect($head['status'])->toBe(403, $origin);
        }
        // Off by default
        [$conn, $head] = ws_handshake($addr, '/websocket', ['Origin' => 'https://evil.example']);
        expect($head['status'])->toBe(101);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('fragments are one message: control frames between them are answered at once, a character may be split, a pong nobody asked for is ignored', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $conn = ws_connect($addr, '/websocket');
        ws_send($conn, 1, "\xE2", false);          // the first byte of ✓
        ws_send($conn, 9, 'one');
        ws_send($conn, 0, "\x9C", false);
        ws_send($conn, 10, 'a pong nobody asked for');
        ws_send($conn, 9, 'two');
        ws_send($conn, 0, "\x93", false);
        $expected = '✓';
        for ($i = 0; $i < 100; ++$i) {
            ws_send($conn, 0, "[$i]", false);
            $expected .= "[$i]";
            if (0 === $i % 25) {
                ws_send($conn, 9, "p$i");
            }
        }
        ws_send($conn, 0, 'end');
        expect([ws_read($conn), ws_read($conn)])->toBe([[10, 'one'], [10, 'two']]);
        foreach ([0, 25, 50, 75] as $i) {
            expect(ws_read($conn))->toBe([10, "p$i"]);
        }
        expect(ws_read($conn))->toBe([1, $expected . 'end']);
        // The connection is as it was
        ws_send($conn, 1, 'next');
        expect(ws_read($conn))->toBe([1, 'next']);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('a ping of 125 bytes is answered with its payload; lengths of 16 and 64 bits are read also for small payloads', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $conn = ws_connect($addr, '/websocket');
        ws_send($conn, 9, str_repeat('p', 125));
        expect(ws_read($conn))->toBe([10, str_repeat('p', 125)]);
        ws_send($conn, 10, '');
        ws_send($conn, 10, str_repeat('q', 125));
        ws_raw($conn, 0x81, 'in 16 bits', bits: 16);
        expect(ws_read($conn))->toBe([1, 'in 16 bits']);
        ws_raw($conn, 0x81, 'in 64 bits', bits: 64);
        expect(ws_read($conn))->toBe([1, 'in 64 bits']);
        foreach ([125, 126, 127, 65535, 65536, 65537] as $size) {
            $payload = random_bytes($size);
            ws_send($conn, 2, $payload);
            expect(ws_read($conn) === [2, $payload])->toBeTrue("a payload of $size bytes");
        }
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('close codes: a valid one is echoed, one that is reserved or out of range is a protocol error, and the reason must be UTF-8', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        foreach ([1000, 1001, 1002, 1003, 1007, 1008, 1009, 1010, 1011, 1012, 1013, 1014, 3000, 3999, 4000, 4999] as $code) {
            $conn = ws_connect($addr, '/websocket');
            ws_send($conn, 8, pack('n', $code) . 'a reason ✓');
            ws_expect_close($conn, $code); // the code only: the reason is not echoed
            fclose($conn);
        }
        foreach ([0, 1, 999, 1004, 1005, 1006, 1015, 1016, 2000, 2999, 5000, 65535] as $code) {
            $conn = ws_connect($addr, '/websocket');
            ws_send($conn, 8, pack('n', $code));
            ws_expect_close($conn, 1002);
            fclose($conn);
        }
        $conn = ws_connect($addr, '/websocket');
        ws_send($conn, 8, "\x03");                                 // half a code
        ws_expect_close($conn, 1002);
        $conn = ws_connect($addr, '/websocket');
        ws_send($conn, 8, pack('n', 1000) . "\xFF\xFE");           // a reason that is not UTF-8
        ws_expect_close($conn, 1007);
        $conn = ws_connect($addr, '/websocket');
        ws_send($conn, 8, pack('n', 1000) . str_repeat('r', 123)); // the longest reason
        ws_expect_close($conn, 1000);
        $conn = ws_connect($addr, '/websocket');
        ws_send($conn, 8, '');                                     // no code at all
        ws_expect_close($conn, 1000);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('the closing handshake, started by either side, ends with the server closing the connection', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        // The client's close is echoed; the server's FIN follows with no help from the client
        $conn = ws_connect($addr, '/websocket');
        ws_send($conn, 8, pack('n', 1000));
        ws_expect_close($conn, 1000);

        // The server's close comes first (the callback returned); the client answers; the server ends it
        $conn = ws_connect($addr, '/websocket');
        ws_send($conn, 1, 'bye');
        expect(ws_read($conn))->toBe([8, pack('n', 1000)]);
        ws_send($conn, 8, pack('n', 1000));
        expect(ws_end($conn))->toBeTrue();

        // The server ends it also when the client never answers
        $conn = ws_connect($addr, '/websocket');
        ws_send($conn, 1, 'bye');
        ws_expect_close($conn, 1000);

        // Whatever the client sends after its close is ignored: no echo, no error
        $conn = ws_connect($addr, '/websocket');
        fwrite($conn, ws_bytes(0x88, pack('n', 1000)) . ws_bytes(0x81, 'after the close') . ws_bytes(0x89, 'and a ping') . ws_bytes(0x81, "\xFF"));
        ws_expect_close($conn, 1000);

        // ... and after the server's close
        $conn = ws_connect($addr, '/websocket');
        ws_send($conn, 1, 'bye');
        ws_send($conn, 1, 'too late');
        ws_send($conn, 8, pack('n', 1000));
        expect(ws_read($conn))->toBe([8, pack('n', 1000)]);
        expect(ws_end($conn))->toBeTrue();
        expect(probe($addr, '/hello'))->toBe('Hello');
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('accept() hands the exchange to the handler: it reads with receive(), and ends the connection itself', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $conn = ws_connect($addr, '/websocket-accept');
        ws_send($conn, 1, 'hello');
        expect(ws_read($conn))->toBe([1, 'hello']);
        ws_send($conn, 2, "\x00\x01");
        expect(ws_read($conn))->toBe([2, "\x00\x01"]);
        ws_send($conn, 9, 'ping');
        expect(ws_read($conn))->toBe([10, 'ping']);
        ws_send($conn, 8, pack('n', 1000));
        ws_expect_close($conn, 1000);

        $conn = ws_connect($addr, '/websocket-accept');
        ws_send($conn, 1, "\xC3\x28");
        ws_expect_close($conn, 1007);

        // The client leaves without a word: the loop ends, nothing is logged
        $conn = ws_connect($addr, '/websocket-accept');
        ws_send($conn, 1, 'x');
        expect(ws_read($conn))->toBe([1, 'x']);
        fclose($conn);
        expect(probe($addr, '/hello'))->toBe('Hello');
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('a callback that only sends (a subscription forwarded) gets every publish, and ends when its client leaves', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode, 2);
    try {
        $clients = [];
        for ($i = 0; $i < 8; ++$i) {
            $clients[] = ws_connect($addr, '/websocket-news');
        }
        expect(ws_live($addr, 'news', 8, 2))->toBe(8);
        expect(probe($addr, '/publish?topic=news&m=first'))->toBe('published');
        expect(probe($addr, '/publish?topic=news&m=second'))->toBe('published');
        foreach ($clients as $conn) {
            expect([ws_read($conn), ws_read($conn)])->toBe([[1, 'first'], [1, 'second']]);
        }

        // Half leave without a word, half say goodbye: every callback ends either way
        foreach ($clients as $i => $conn) {
            $i % 2 ? ws_send($conn, 8, pack('n', 1000)) : fclose($conn);
        }
        expect(ws_live($addr, 'news', 0, 2))->toBe(0);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('a client that resets its connection ends the callback, without an error in the log', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $conn = ws_connect($addr, '/websocket-news');
        expect(ws_live($addr, 'news', 1))->toBe(1);
        ws_reset($conn);
        expect(ws_live($addr, 'news', 0))->toBe(0);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('quiet connections are pinged every ping interval, by one coroutine for all, which outlives the first connection', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode, env: ['SWERVE_TEST_PING' => '1']);
    try {
        $a = ws_connect($addr, '/websocket');
        $b = ws_connect($addr, '/websocket-news');
        $c = ws_connect($addr, '/websocket-accept');
        foreach ([$a, $b, $c] as $conn) {
            expect(ws_frame($conn))->toBe(['fin' => true, 'rsv' => 0, 'op' => 9, 'masked' => false, 'payload' => '']);
            ws_send($conn, 10, '');
        }
        // The pings go on, also after the first connection that started the loop is gone
        ws_send($a, 8, pack('n', 1000));
        ws_expect_close($a, 1000);
        expect(ws_read($b))->toBe([9, '']);
        ws_send($c, 1, 'still here');
        expect([ws_read($c), ws_read($c)])->toContain([1, 'still here']); // a ping may come before the echo
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('a subscription loop forwards messages and ends the socket when told to', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode, 2);
    try {
        $clients = [];
        for ($i = 0; $i < 4; ++$i) {
            $clients[] = ws_connect($addr, '/websocket-feed');
        }
        usleep(300_000); // every callback subscribed
        expect(probe($addr, '/publish?topic=feed&m=one'))->toBe('published');
        expect(probe($addr, '/publish-json?topic=feed&m=two'))->toBe('published');
        foreach ($clients as $conn) {
            expect(ws_read($conn))->toBe([1, 'one']);
            expect(json_decode(ws_read($conn)[1], true))->toBe(['m' => 'two', 'n' => 1, 'list' => [1, 2]]);
        }
        expect(probe($addr, '/publish-end?topic=feed'))->toBe('published');
        foreach ($clients as $conn) {
            expect(ws_read($conn))->toBe([8, pack('n', 1000)]);
            ws_send($conn, 8, pack('n', 1000));
            expect(ws_end($conn))->toBeTrue();
            fclose($conn);
        }
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('events in, sequential code out: a message from one client reaches the other, and a published end returns the callback', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $a = ws_connect($addr, '/websocket-chat');
        $b = ws_connect($addr, '/websocket-chat');
        usleep(300_000); // both callbacks subscribed
        ws_send($a, 1, 'hello from a');
        expect(ws_read($b))->toBe([1, 'hello from a']);
        expect(ws_read($a))->toBe([1, 'hello from a']);
        ws_send($b, 1, 'and b');
        expect(ws_read($a))->toBe([1, 'and b']);
        expect(ws_read($b))->toBe([1, 'and b']);
        expect(probe($addr, '/publish?topic=chat&m=end'))->toBe('published');
        foreach ([$a, $b] as $conn) {
            expect(ws_read($conn))->toBe([8, pack('n', 1000)]);
            ws_send($conn, 8, pack('n', 1000));
            expect(ws_end($conn))->toBeTrue();
        }
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('$onMessage gets the data and whether it is binary', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $conn = ws_connect($addr, '/websocket-events');
        ws_send($conn, 1, 'hi');
        expect(ws_read($conn))->toBe([1, 't:' . bin2hex('hi')]);
        ws_send($conn, 2, "\x00\xFF");
        expect(ws_read($conn))->toBe([1, 'b:00ff']);
        ws_send($conn, 1, 'frag', false);
        ws_send($conn, 0, 'mented');
        expect(ws_read($conn))->toBe([1, 't:' . bin2hex('fragmented')]);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('$onClose fires once when the client says goodbye, with its code and reason, or 1005 without one', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $conn = ws_connect($addr, '/websocket-events');
        ws_send($conn, 8, pack('n', 1001) . 'leaving');
        expect(ws_read($conn))->toBe([8, pack('n', 1001)]);
        expect(ws_closes($addr, 1))->toBe([[1001, 'leaving']]);

        $conn = ws_connect($addr, '/websocket-events');
        ws_send($conn, 8, '');
        expect(ws_read($conn))->toBe([8, pack('n', 1000)]);
        expect(ws_closes($addr, 2))->toBe([[1001, 'leaving'], [1005, '']]);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('$onClose fires once when the server calls end(), with the code and reason it gave', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $conn = ws_connect($addr, '/websocket-events');
        ws_send($conn, 1, 'end');
        expect(ws_read($conn))->toBe([8, pack('n', 4000) . 'server done']);
        ws_send($conn, 8, pack('n', 4000));
        expect(ws_end($conn))->toBeTrue();
        expect(ws_closes($addr, 1))->toBe([[4000, 'server done']]);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('$onClose fires once, with 1006, when the connection is reset', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $conn = ws_connect($addr, '/websocket-events');
        ws_send($conn, 1, 'x');
        expect(ws_read($conn))->toBe([1, 't:78']); // the server is serving it
        ws_reset($conn);
        expect(ws_closes($addr, 1))->toBe([[1006, '']]);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('a throwing $onMessage listener closes with 1011, is logged, and $onClose still fires', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $conn = ws_connect($addr, '/websocket-events');
        ws_send($conn, 1, 'throw');
        ws_expect_close($conn, 1011);
        log_wait($log, '/the WebSocket listener failed/');
        expect(ws_closes($addr, 1))->toBe([[1011, '']]);
    } finally {
        native_stop($process);
    }
})->with('modes');

test('receive() throws a LogicException while $onMessage has listeners', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $conn = ws_connect($addr, '/websocket-mixed');
        expect(ws_read($conn))->toBe([1, 'LogicException']);
        expect(ws_read($conn))->toBe([8, pack('n', 1000)]);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('a once() listener gets the first message, and the next ones can be received', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $conn = ws_connect($addr, '/websocket-once');
        ws_send($conn, 1, 'one');
        ws_send($conn, 1, 'two');
        ws_send($conn, 1, 'three');
        expect([ws_read($conn), ws_read($conn), ws_read($conn)])->toBe([[1, 'once:one'], [1, 'pull:two'], [1, 'pull:three']]);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('send() from many coroutines at once: every message arrives whole, each coroutine\'s in order', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $conn = ws_connect($addr, '/websocket-concurrent');
        $next = array_fill(0, 10, 0);
        while (($frame = ws_read($conn)) && 8 !== $frame[0]) {
            expect($frame[0])->toBe(1);
            [$who, $n, $filler] = explode(':', $frame[1]);
            expect([(int) $n, strlen($filler)])->toBe([$next[$who]++, ($who * 7919 + $n * 104729) % 70000]);
        }
        expect($frame)->toBe([8, pack('n', 1000)]);
        expect($next)->toBe(array_fill(0, 10, 30));
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('backpressure out: send() waits for a client that does not read, and the worker does not buffer for it', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $conn = ws_connect($addr, '/websocket-flood'); // 1000 messages of 100 kB, and the client reads none
        $deadline = microtime(true) + 10;
        $stable   = [-1, 0];
        // Wait until the sending stops moving: stuck in send(), with the socket buffers full
        while (microtime(true) < $deadline && $stable[1] < 6) {
            usleep(100_000);
            $sent   = (int) probe($addr, '/progress');
            $stable = $sent === $stable[0] ? [$sent, $stable[1] + 1] : [$sent, 0];
        }
        expect($stable[1])->toBe(6);
        expect($stable[0])->toBeGreaterThan(0)->toBeLessThan(400);   // 40 MB would be a buffer of its own
        expect((int) probe($addr, '/mem'))->toBeLessThan(64 << 20);

        // Reading lets it go on, and nothing was lost
        $n = 0;
        while ([2, str_repeat('x', 100_000)] === ($frame = ws_read($conn))) {
            ++$n;
        }
        expect([$n, $frame])->toBe([1000, [1, 'flooded']]);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('backpressure in: a callback that takes no message makes the worker stop reading, so a client sending fast is held', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $conn = ws_connect($addr, '/websocket-slow'); // takes nothing for 1.5 s, then counts
        stream_set_blocking($conn, false);
        $frame   = ws_bytes(0x82, str_repeat('m', 100_000));
        $total   = 600;                                  // 60 MB
        $sent    = 0;
        $pending = '';
        $until   = microtime(true) + 1.0;
        $held    = null;
        $limit   = microtime(true) + 30;
        while (($sent < $total || '' !== $pending) && microtime(true) < $limit) {
            if ('' === $pending && $sent < $total) {
                $pending = $frame;
                ++$sent;
            }
            $n       = fwrite($conn, $pending);
            $pending = (string) substr($pending, (int) $n);
            if (!$n) {
                $r = null;
                $w = [$conn];
                $e = null;
                stream_select($r, $w, $e, 0, 50_000);
            }
            if (null === $held && microtime(true) >= $until) {
                $held = $sent;
            }
        }
        // In the first second only what the inbox (64 messages) and the socket buffers hold got through
        expect($held)->not->toBeNull()->toBeLessThan(300);   // 30 MB
        expect($sent)->toBe($total);
        stream_set_blocking($conn, true);
        ws_send($conn, 1, 'done');
        expect(ws_read($conn))->toBe([1, "received:$total"]);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('many sockets in one worker: 200 echo at once while ordinary requests are answered promptly', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $clients = [];
        for ($i = 0; $i < 200; ++$i) {
            $clients[$i] = ws_connect($addr, 0 === $i % 2 ? '/websocket' : '/websocket-accept');
        }
        foreach ($clients as $i => $conn) {
            ws_send($conn, 1, "hello $i");
        }
        foreach ($clients as $i => $conn) {
            expect(ws_read($conn))->toBe([1, "hello $i"]);
        }
        // With every socket open, a plain request is not made to wait
        for ($i = 0; $i < 20; ++$i) {
            $start = microtime(true);
            expect(probe($addr, '/hello', 5.0))->toBe('Hello');
            expect(microtime(true) - $start)->toBeLessThan(1.0);
        }
        // ... also while every socket sends at once
        foreach ($clients as $i => $conn) {
            ws_send($conn, 2, str_repeat("$i", 50_000));
        }
        $start = microtime(true);
        expect(probe($addr, '/hello', 5.0))->toBe('Hello');
        expect(microtime(true) - $start)->toBeLessThan(1.0);
        foreach ($clients as $i => $conn) {
            expect(ws_read($conn) === [2, str_repeat("$i", 50_000)])->toBeTrue();
        }
        foreach ($clients as $conn) {
            ws_send($conn, 8, pack('n', 1000));
        }
        foreach ($clients as $conn) {
            ws_expect_close($conn, 1000);
        }
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('a drain (shutdown) with sockets open closes every one with 1001, and swerve exits 0 with a clean log', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    $paths   = ['/websocket', '/websocket', '/websocket-accept', '/websocket-news', '/websocket-events', '/websocket-flood'];
    $clients = [];
    foreach ($paths as $i => $path) {
        $clients[$i] = ws_connect($addr, $path);
    }
    ws_send($clients[0], 1, 'hi');
    expect(ws_read($clients[0]))->toBe([1, 'hi']);
    ws_send($clients[2], 1, 'hi');
    expect(ws_read($clients[2]))->toBe([1, 'hi']);
    swerve_signal($process, SIGTERM);
    foreach ($clients as $i => $conn) {
        // The flood's frames come first; the others have nothing but the goodbye
        while (($frame = ws_read($conn)) && 8 !== $frame[0]) {
        }
        expect($frame)->toBe([8, pack('n', 1001)], $paths[$i]);
        // The client's half of the closing handshake, and its close when swerve closes it, as a browser's
        ws_send($conn, 8, pack('n', 1001));
        fread($conn, 1);
        fclose($conn);
    }
    [$code, $seconds] = swerve_wait($process, 8);
    expect($code)->toBe(0);
    expect($seconds)->toBeLessThan(4.0, (string) file_get_contents($log));
    expect(log_count($log, '/(ERROR|CRITICAL|WARNING|failed|deadline|Warning:)/'))->toBe(0, (string) file_get_contents($log));
})->with('modes');

test('a drain closes a socket whose client says nothing, within the closing linger', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    $conn = ws_connect($addr, '/websocket');
    swerve_signal($process, SIGTERM);
    expect(ws_read($conn))->toBe([8, pack('n', 1001)]);   // and the client keeps silent
    [$code, $seconds] = swerve_wait($process, 8);
    expect($code)->toBe(0);
    expect($seconds)->toBeLessThan(5.0, (string) file_get_contents($log));
    expect(log_count($log, '/(ERROR|CRITICAL|WARNING|failed|Warning:)/'))->toBe(0, (string) file_get_contents($log));
})->with('modes');

test('random bytes after a valid handshake: the server closes with a close code or drops the connection, never hangs or logs an error', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    $seed  = random_int(1, 1 << 30);
    $valid = fn (int $code) => $code >= 1000 && $code <= 1003 || $code >= 1007 && $code <= 1014 || $code >= 3000 && $code <= 4999;
    mt_srand($seed);
    try {
        for ($round = 0; $round < 60; ++$round) {
            $conn  = ws_connect($addr, 0 === $round % 2 ? '/websocket' : '/websocket-accept');
            $bytes = '';
            for ($i = mt_rand(2, 400); $i > 0; --$i) {
                $bytes .= chr(mt_rand(0, 255));
            }
            if (0 === $round % 3) {
                $bytes[1] = chr(ord($bytes[1]) | 0x80);          // masked: the parser goes deeper
            }
            if (0 === $round % 5) {
                $bytes[0] = chr(ord($bytes[0]) & 0x8F);          // no reserved bits
            }
            fwrite($conn, $bytes);
            stream_socket_shutdown($conn, STREAM_SHUT_WR);        // a frame waiting for its rest ends too
            $start   = microtime(true);
            $frames  = [];
            while (null !== ($frame = ws_frame($conn))) {
                $frames[] = $frame;
            }
            $context = "seed $seed, round $round, bytes " . bin2hex($bytes);
            expect(microtime(true) - $start)->toBeLessThan(4.0, $context);
            foreach ($frames as $frame) {
                expect([$frame['fin'], $frame['rsv'], $frame['masked']])->toBe([true, 0, false], $context);
                expect($frame['op'])->toBeIn([1, 2, 8, 10], $context);
            }
            if ($frames) {
                $last = end($frames);
                expect($last['op'])->toBe(8, $context);
                expect($valid(unpack('n', $last['payload'])[1]))->toBeTrue($context);
            }
            fclose($conn);
        }
        expect(probe($addr, '/hello'))->toBe('Hello');
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');
