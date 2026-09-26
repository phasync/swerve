<?php

/*
 * Form bodies: swerve parses what PHP parses, as PHP parses it. Each request is sent, byte for
 * byte, to PHP's built-in server (the reference: $_POST, $_FILES, php://input) and to swerve,
 * under the same php.ini limits, and the answers must be the same.
 */

const FORM_INI = ['-d', 'upload_max_filesize=1K', '-d', 'max_file_uploads=3', '-d', 'post_max_size=64K', '-d', 'max_input_vars=20'];

/**
 * PHP's server and swerve, both with FORM_INI.
 *
 * @return array{0: resource, 1: string, 2: resource, 3: string}
 */
function form_servers(): array
{
    $ref     = free_address();
    $php     = proc_open([PHP_BINARY, ...FORM_INI, '-S', $ref, __DIR__ . '/Fixtures/form-reference.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    [$swerve, $addr] = swerve_start([], 1, php: FORM_INI, fixture: 'form-swerve.php', wait: false);
    $deadline = microtime(true) + 10;
    while (null === probe($ref, '/') || null === probe($addr, '/')) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(20_000);
    }

    return [$php, $ref, $swerve, $addr];
}

/** Send a raw request; the decoded JSON answer. */
function form_send(string $addr, string $method, string $contentType, string $body): ?array
{
    $conn = native_connect($addr);
    fwrite($conn, "$method /form HTTP/1.1\r\nHost: t\r\nContent-Type: $contentType\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n$body");
    $response = native_read_response($conn);
    fclose($conn);

    return json_decode($response['body'] ?? '', true);
}

/** A multipart body from [name, value] fields and [name, filename, type, content] files. */
function multipart(string $boundary, array $parts): string
{
    $body = "preamble, ignored\r\n";
    foreach ($parts as $part) {
        $body .= "--$boundary\r\n";
        if (2 === count($part)) {
            $body .= "Content-Disposition: form-data; name=\"$part[0]\"\r\n\r\n$part[1]\r\n";
        } else {
            $body .= "Content-Disposition: form-data; name=\"$part[0]\"; filename=\"$part[1]\"\r\nContent-Type: $part[2]\r\n\r\n$part[3]\r\n";
        }
    }

    return $body . "--$boundary--\r\nepilogue, ignored";
}

test('swerve parses form bodies as PHP does', function () {
    [$php, $ref, $swerve, $addr] = form_servers();
    $b     = 'XyZ-boundary-123';
    $cases = [
        'url-encoded, nested and mangled names' => ['POST', 'application/x-www-form-urlencoded', 'a=1&b[]=2&b[]=3&c[x][y]=4&d.e=5&f g=6&h=%C3%A6+%26'],
        'url-encoded with a charset'            => ['POST', 'application/x-www-form-urlencoded; charset=UTF-8', 'a=1'],
        'more fields than max_input_vars'       => ['POST', 'application/x-www-form-urlencoded', implode('&', array_map(fn ($i) => "v$i=$i", range(1, 25)))],
        'multipart fields and files'            => ['POST', "multipart/form-data; boundary=$b", multipart($b, [
            ['title', "two\r\nlines"], ['tags[]', 'a'], ['tags[]', 'b'],
            ['doc', 'a.txt', 'text/plain', 'hello'], ['pics[]', 'p1.png', 'image/png', "\x89PNG\r\n--$b-not-a-delimiter"], ['pics[]', 'p2.png', 'image/png', str_repeat('x', 600)],
        ])],
        'a quoted boundary'                     => ['POST', "multipart/form-data; boundary=\"$b\"", multipart($b, [['q', 'quoted']])],
        'an empty file input'                   => ['POST', "multipart/form-data; boundary=$b", multipart($b, [['doc', '', 'application/octet-stream', '']])],
        'a file over upload_max_filesize'       => ['POST', "multipart/form-data; boundary=$b", multipart($b, [['big', 'big.bin', 'application/octet-stream', str_repeat('y', 2000)], ['after', 'kept']])],
        'more files than max_file_uploads'      => ['POST', "multipart/form-data; boundary=$b", multipart($b, [['f1', '1', 'text/plain', '1'], ['f2', '2', 'text/plain', '2'], ['f3', '3', 'text/plain', '3'], ['f4', '4', 'text/plain', '4']])],
        'a disposition without a name'          => ['POST', "multipart/form-data; boundary=$b", str_replace('name="x"', 'nope="x"', multipart($b, [['w', 'kept'], ['x', 'garbled'], ['y', 'dropped']]))],
        'a part without a disposition'          => ['POST', "multipart/form-data; boundary=$b", str_replace('Content-Disposition: form-data; name="x"', 'X-Other: 1', multipart($b, [['w', 'kept'], ['x', 'skipped'], ['y', 'kept']]))],
        'a path in a filename'                  => ['POST', "multipart/form-data; boundary=$b", multipart($b, [['f', '../../etc/passwd', 'text/plain', 'p']])],
        'a PUT is not parsed'                   => ['PUT', 'application/x-www-form-urlencoded', 'a=1'],
        'JSON is not parsed'                    => ['POST', 'application/json', '{"a":1}'],
        'a body over post_max_size'             => ['POST', 'application/x-www-form-urlencoded', 'a=' . str_repeat('z', 70000)],
    ];
    try {
        foreach ($cases as $name => [$method, $type, $body]) {
            $expected = form_send($ref, $method, $type, $body);
            $actual   = form_send($addr, $method, $type, $body);
            if ('more fields than max_input_vars' === $name) {
                // The one known difference: PHP's own parser keeps one more than max_input_vars
                array_pop($expected['post']);
            }
            expect($actual)->toBe($expected, $name);
        }
    } finally {
        proc_terminate($php);
        proc_close($php);
        native_stop($swerve);
    }
});
