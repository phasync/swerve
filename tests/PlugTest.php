<?php

/*
 * Without ext-phasync, application code is loaded through the plug (phasync/babel translating it to
 * call phasync/hooked): blocking functions wait cooperatively, and PHP_SAPI is 'swerve' inside a
 * request and 'cli' outside one, as with the extension's sapi().
 */

beforeEach(function () {
    if (extension_loaded('phasync')) {
        $this->markTestSkipped('ext-phasync is loaded: the plug is only installed without it');
    }
});

it('makes a blocking sleep() cooperative: two requests sleeping a second each take a second together', function () {
    [, $addr] = swerve_start(workers: 1, fixture: 'plug.php');
    $conns = [];
    $start = microtime(true);
    foreach ([1, 2] as $i) {
        $conns[$i] = stream_socket_client("tcp://$addr");
        fwrite($conns[$i], "GET /sleep HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
    }
    $bodies = array_map(static fn ($c) => (string) stream_get_contents($c), $conns);
    expect(microtime(true) - $start)->toBeLessThan(1.6)
        ->and($bodies[1])->toContain('boot=cli request=swerve name=swerve')
        ->and($bodies[2])->toContain('boot=cli request=swerve name=swerve');
});

it('runs a classic script handler on phasync/hooked\'s sapi(): its echo, header() and $_POST are the request\'s own', function () {
    [, $addr] = swerve_start(workers: 1, fixture: 'plug-script.php');
    $conn = stream_socket_client("tcp://$addr");
    fwrite($conn, "POST /form HTTP/1.1\r\nHost: t\r\nConnection: close\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: 7\r\n\r\nk=v&w=2");
    $response = (string) stream_get_contents($conn);
    expect($response)->toStartWith("HTTP/1.1 201 ")
        ->and($response)->toContain("x-sapi: swerve\r\n")
        ->and($response)->toContain("set-cookie: seen=yes\r\n")
        ->and(plug_dechunk($response))->toBe('post={"k":"v","w":"2"} input=k=v&w=2 inline');
});

it('keeps two overlapping requests\' output buffers and headers apart', function () {
    [, $addr] = swerve_start(workers: 1, fixture: 'plug-script.php');
    $conns = [];
    $start = microtime(true);
    foreach ([1, 2] as $n) {
        $conns[$n] = stream_socket_client("tcp://$addr");
        fwrite($conns[$n], "GET /sleep?n=$n HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
    }
    $responses = array_map(static fn ($c) => (string) stream_get_contents($c), $conns);
    expect(microtime(true) - $start)->toBeLessThan(1.6);
    foreach ($responses as $n => $response) {
        expect($response)->toContain("x-slept: 1\r\n")->and($response)->toContain("before after $n");
    }
});

/** The body of a chunked response. */
function plug_dechunk(string $response): string
{
    $rest = substr($response, strpos($response, "\r\n\r\n") + 4);
    $body = '';
    while ('0' !== ($size = substr($rest, 0, strpos($rest, "\r\n")))) {
        $rest  = substr($rest, strlen($size) + 2);
        $body .= substr($rest, 0, hexdec($size));
        $rest  = substr($rest, hexdec($size) + 2);
    }

    return $body;
}
