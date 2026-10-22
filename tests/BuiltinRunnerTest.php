<?php

/*
 * BuiltinRunner forwards a request to a pool of `php -S` processes it manages itself (the
 * sibling of ServerRunner, which uses ext-phasync's request server instead): run without the
 * extension loaded, exercising it directly through a ClientRequest, as ClientRequestTest.php
 * does for the module itself.
 */

use phasync\Net\StreamDuplex;
use Psr\Log\NullLogger;
use Swerve\ClientRequest;
use Swerve\Http\HttpConnection;
use Swerve\Util\Classic\BuiltinRunner;

/**
 * One phasync::run() holding a BuiltinRunner of its own and a $send closure to drive it: $send
 * opens a fresh connection (as a browser's would be, `Connection: close`), forwards one request
 * through $runner, and returns the parsed response. Several calls to $send inside $act share the
 * same runner, and so the same process pool.
 *
 * @return mixed whatever $act returns
 */
function builtin_session(string $publicRoot, int $max, float $idle, Closure $act): mixed
{
    return phasync::run(function () use ($publicRoot, $max, $idle, $act) {
        $runner = new BuiltinRunner($publicRoot, $max, $idle);
        $send   = function (string $script, string $scriptName, ?string $pathInfo, string $method, string $target, string $headers = '', string $body = '') use ($runner): array {
            $handler = static function (ClientRequest $r) use ($runner, $script, $scriptName, $pathInfo): void {
                $runner->forward($r, $script, $scriptName, $pathInfo);
            };
            [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_SEQPACKET, 0);
            stream_set_blocking($server, false);
            stream_set_blocking($client, false);
            stream_set_read_buffer($client, 0);
            $connection = new HttpConnection(new StreamDuplex($server, '127.0.0.1:1'), $handler, new NullLogger(), null);
            phasync::go($connection->serve(...));

            $contentLength = '' === $body ? '' : 'Content-Length: ' . strlen($body) . "\r\n";
            fwrite($client, "$method $target HTTP/1.1\r\nHost: t\r\n{$headers}{$contentLength}Connection: close\r\n\r\n$body");
            stream_socket_shutdown($client, STREAM_SHUT_WR);

            $raw = '';
            while (true) {
                $packet = fread(phasync::readable($client, 5), 1 << 20);
                if (false === $packet || ('' === $packet && feof($client))) {
                    break;
                }
                $raw .= $packet;
            }
            fclose($client);

            return br_parse($raw);
        };

        return $act($runner, $send);
    });
}

/** One request through a session of its own, for tests that don't need the pool across requests. */
function builtin_once(string $publicRoot, string $script, string $scriptName, ?string $pathInfo, string $method, string $target, string $headers = '', string $body = ''): array
{
    return builtin_session($publicRoot, 1, 0.3, fn ($runner, $send) => $send($script, $scriptName, $pathInfo, $method, $target, $headers, $body));
}

/**
 * A raw HTTP/1.1 response to status, headers (lowercase name => values, in order) and the body,
 * de-chunked if the backend sent it that way.
 *
 * @return array{status: int, headers: array<string, list<string>>, body: string}
 */
function br_parse(string $raw): array
{
    [$head, $rest] = explode("\r\n\r\n", $raw, 2);
    $lines    = explode("\r\n", $head);
    $status   = (int) explode(' ', array_shift($lines), 3)[1];
    $headers  = [];
    foreach ($lines as $line) {
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))][] = trim($value);
    }
    $body = $rest;
    if (in_array('chunked', array_map('strtolower', $headers['transfer-encoding'] ?? []), true)) {
        $out = '';
        while (true) {
            $eol  = strpos($body, "\r\n");
            $size = hexdec(trim(substr($body, 0, $eol)));
            if (0 === $size) {
                break;
            }
            $out .= substr($body, $eol + 2, $size);
            $body = substr($body, $eol + 2 + $size + 2);
        }
        $body = $out;
    }

    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

test('a GET with a query string reaches $_GET, with the request target unchanged', function () {
    $docroot = temp_path(true);
    file_put_contents("$docroot/index.php", <<<'PHP'
        <?php
        echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'get' => $_GET, 'uri' => $_SERVER['REQUEST_URI']]);
        PHP);

    $response = builtin_once($docroot, "$docroot/index.php", '/index.php', null, 'GET', '/index.php?a=1&b=two');

    expect($response['status'])->toBe(200);
    expect(json_decode($response['body'], true))->toBe([
        'method' => 'GET', 'get' => ['a' => '1', 'b' => 'two'], 'uri' => '/index.php?a=1&b=two',
    ]);
});

test('a POST body reaches php://input and $_POST', function () {
    $docroot = temp_path(true);
    file_put_contents("$docroot/post.php", <<<'PHP'
        <?php
        echo json_encode(['input' => file_get_contents('php://input'), 'post' => $_POST]);
        PHP);

    $response = builtin_once(
        $docroot, "$docroot/post.php", '/post.php', null,
        'POST', '/post.php', "Content-Type: application/x-www-form-urlencoded\r\n", 'a=1&b=two',
    );

    expect($response['status'])->toBe(200);
    expect(json_decode($response['body'], true))->toBe(['input' => 'a=1&b=two', 'post' => ['a' => '1', 'b' => 'two']]);
});

test('SCRIPT_NAME, SCRIPT_FILENAME, PHP_SELF, PATH_INFO and DOCUMENT_ROOT are exactly as the router gave them', function () {
    $docroot = temp_path(true);
    mkdir("$docroot/sub");
    file_put_contents("$docroot/sub/index.php", <<<'PHP'
        <?php
        echo json_encode([
            'script_name'     => $_SERVER['SCRIPT_NAME'],
            'script_filename' => $_SERVER['SCRIPT_FILENAME'],
            'php_self'        => $_SERVER['PHP_SELF'],
            'path_info'       => $_SERVER['PATH_INFO'] ?? null,
            'document_root'   => $_SERVER['DOCUMENT_ROOT'],
        ]);
        PHP);
    $script = "$docroot/sub/index.php";

    $response = builtin_once($docroot, $script, '/sub/index.php', '/extra/path', 'GET', '/sub/index.php/extra/path');

    expect(json_decode($response['body'], true))->toBe([
        'script_name'     => '/sub/index.php',
        'script_filename' => $script,
        'php_self'        => '/sub/index.php/extra/path',
        'path_info'       => '/extra/path',
        'document_root'   => $docroot,
    ]);
});

test('with no PATH_INFO, PHP_SELF is just SCRIPT_NAME and PATH_INFO is unset', function () {
    $docroot = temp_path(true);
    file_put_contents("$docroot/index.php", <<<'PHP'
        <?php
        echo json_encode(['php_self' => $_SERVER['PHP_SELF'], 'has_path_info' => array_key_exists('PATH_INFO', $_SERVER)]);
        PHP);

    $response = builtin_once($docroot, "$docroot/index.php", '/index.php', null, 'GET', '/index.php');
    expect(json_decode($response['body'], true))->toBe(['php_self' => '/index.php', 'has_path_info' => false]);

    // The router, for a path that doesn't exist: php -S would make that path its PATH_INFO
    $response = builtin_once($docroot, "$docroot/index.php", '/index.php', null, 'GET', '/x/y?q=1');
    expect(json_decode($response['body'], true))->toBe(['php_self' => '/index.php', 'has_path_info' => false]);
});

test('the client identity is in $_SERVER, and the private headers that carried it never reach the script', function () {
    $docroot = temp_path(true);
    file_put_contents("$docroot/who.php", <<<'PHP'
        <?php
        echo json_encode([
            'remote'      => $_SERVER['REMOTE_ADDR'] ?? null,
            'https'       => $_SERVER['HTTPS'] ?? null,
            'host'        => $_SERVER['HTTP_HOST'] ?? null,
            'server_name' => $_SERVER['SERVER_NAME'] ?? null,
            'spoof'       => $_SERVER['HTTP_X_SWERVE_SCRIPT'] ?? 'not set',
        ]);
        PHP);

    $response = builtin_once($docroot, "$docroot/who.php", '/who.php', null, 'GET', '/who.php');

    expect(json_decode($response['body'], true))->toBe([
        'remote' => '127.0.0.1', 'https' => null, 'host' => 't', 'server_name' => 't', 'spoof' => 'not set',
    ]);
});

test('a client-sent copy of a private header is stripped, not trusted', function () {
    $docroot = temp_path(true);
    file_put_contents("$docroot/who.php", '<?php echo json_encode(["remote" => $_SERVER["REMOTE_ADDR"] ?? null]);');

    $response = builtin_once(
        $docroot, "$docroot/who.php", '/who.php', null,
        'GET', '/who.php', "X-Swerve-Remote-Addr: 9.9.9.9\r\n",
    );

    expect(json_decode($response['body'], true)['remote'])->toBe('127.0.0.1'); // not the client's spoofed 9.9.9.9
});

test('header(), http_response_code() and several Set-Cookie headers reach the client', function () {
    $docroot = temp_path(true);
    file_put_contents("$docroot/headers.php", <<<'PHP'
        <?php
        http_response_code(201);
        header('X-Custom: yes');
        header('Set-Cookie: a=1', false);
        header('Set-Cookie: b=2', false);
        echo 'created';
        PHP);

    $response = builtin_once($docroot, "$docroot/headers.php", '/headers.php', null, 'GET', '/headers.php');

    expect($response['status'])->toBe(201);
    expect($response['headers']['x-custom'])->toBe(['yes']);
    expect($response['headers']['set-cookie'])->toBe(['a=1', 'b=2']);
    expect($response['body'])->toBe('created');
});

test('flush() streams output to the client before the script ends', function () {
    $docroot = temp_path(true);
    file_put_contents("$docroot/flush.php", <<<'PHP'
        <?php
        echo 'first-part';
        flush();
        usleep(200000);
        echo 'second-part';
        PHP);

    [$firstSeenAfter, $body] = builtin_session($docroot, 1, 0.3, function ($runner, $send) use ($docroot) {
        $handler = static function (ClientRequest $r) use ($runner, $docroot): void {
            $runner->forward($r, "$docroot/flush.php", '/flush.php', null);
        };
        [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_SEQPACKET, 0);
        stream_set_blocking($server, false);
        stream_set_blocking($client, false);
        stream_set_read_buffer($client, 0);
        $connection = new HttpConnection(new StreamDuplex($server, '127.0.0.1:1'), $handler, new NullLogger(), null);
        phasync::go($connection->serve(...));

        fwrite($client, "GET /flush.php HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
        stream_socket_shutdown($client, STREAM_SHUT_WR);

        $start = microtime(true);
        $buf   = '';
        while (!str_contains($buf, 'first-part')) {
            $buf .= fread(phasync::readable($client, 5), 65536);
        }
        $firstSeenAfter = microtime(true) - $start;
        while (false !== ($more = fread(phasync::readable($client, 5), 65536)) && '' !== $more) {
            $buf .= $more;
        }
        fclose($client);

        return [$firstSeenAfter, $buf];
    });

    // Well under the 200 ms the script sleeps between its two echoes: a proxy buffering the
    // whole response before sending anything would make this close to 200 ms instead.
    expect($firstSeenAfter)->toBeLessThan(0.15);
    expect($body)->toContain('first-part')->toContain('second-part');
});

test('a fatal error gives the status the script already set, and the next request on the same pool still works', function () {
    $docroot = temp_path(true);
    file_put_contents("$docroot/fatal.php", <<<'PHP'
        <?php
        http_response_code(500);
        echo 'before the fatal error';
        nonexistent_function_call_xyz();
        PHP);
    file_put_contents("$docroot/ok.php", '<?php echo "still working";');

    [$first, $second] = builtin_session($docroot, 1, 0.3, function ($runner, $send) use ($docroot) {
        return [
            $send("$docroot/fatal.php", '/fatal.php', null, 'GET', '/fatal.php'),
            $send("$docroot/ok.php", '/ok.php', null, 'GET', '/ok.php'),
        ];
    });

    expect($first['status'])->toBe(500);
    expect($first['body'])->toContain('before the fatal error');
    expect($second['status'])->toBe(200);
    expect($second['body'])->toBe('still working');
});

test('a process idle longer than the timeout is stopped', function () {
    $docroot = temp_path(true);
    file_put_contents("$docroot/pid.php", '<?php echo getmypid();');

    [$pid, $aliveAfterIdle] = builtin_session($docroot, 1, 0.2, function ($runner, $send) use ($docroot) {
        $pid = (int) $send("$docroot/pid.php", '/pid.php', null, 'GET', '/pid.php')['body'];
        // Past the idle timeout, plus margin for the sweep coroutine to run and reap it.
        phasync::sleep(0.2 + 0.4);

        return [$pid, posix_kill($pid, 0)];
    });

    expect($pid)->toBeGreaterThan(0);
    expect($aliveAfterIdle)->toBeFalse();
});
