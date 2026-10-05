<?php

/*
 * The ClientRequest contract, in process: a handler served over a socketpair, every write of the
 * module one packet, so the tests see what goes out together.
 */

use Swerve\ClientRequest;
use Swerve\HeadersSentException;

/** One GET, then the client's end of the connection closes. */
function cr_get(Closure $handler, string $headers = '', string $method = 'GET', string $version = '1.1'): array
{
    return native_serve_packets($handler, "$method / HTTP/$version\r\nHost: t\r\n$headers\r\n", true);
}

/** The status line and the headers of the first response, lowercased by name. */
function cr_head(string $packets): array
{
    [$head] = explode("\r\n\r\n", $packets, 2);
    $lines  = explode("\r\n", $head);
    $out    = ['status' => array_shift($lines)];
    foreach ($lines as $line) {
        [$name, $value]            = explode(': ', $line, 2);
        $out[strtolower($name)] = $value;
    }

    return $out;
}

test('a second final head throws a HeadersSentException, also before the first body bytes', function () {
    $caught  = [];
    $handler = function (ClientRequest $r) use (&$caught) {
        $r->sendResponseHeaders(200, ['content-length' => '2']);
        try {
            $r->sendResponseHeaders(500);
        } catch (HeadersSentException $e) {
            $caught[] = $r->headersSent();
        }
        $r->write('ok');
        try {
            $r->sendResponseHeaders(500);
        } catch (HeadersSentException $e) {
            $caught[] = $r->headersSent();
        }
    };
    $raw = implode('', cr_get($handler));

    expect($caught)->toBe([true, true]);
    expect($raw)->toStartWith('HTTP/1.1 200 OK')->toEndWith("\r\n\r\nok");
});

test('end(), write() and flush() without a head send an implicit 200', function (Closure $act, string $body) {
    $raw = implode('', cr_get(function (ClientRequest $r) use ($act) {
        expect($r->headersSent())->toBeFalse();
        $act($r);
        expect($r->headersSent())->toBeTrue();
    }));

    expect($raw)->toStartWith("HTTP/1.1 200 OK\r\n")->toEndWith($body);
})->with([
    'end'   => [fn (ClientRequest $r) => $r->end(), "Content-Length: 0\r\n\r\n"],
    'write' => [function (ClientRequest $r) {
        $r->write('abc');
    }, "\r\n\r\n3\r\nabc\r\n0\r\n\r\n"],
    'flush' => [fn (ClientRequest $r) => $r->flush(), "Transfer-Encoding: chunked\r\n\r\n0\r\n\r\n"],
]);

test('a held head with no body and no length is content-length: 0; one the handler gave is kept', function () {
    $empty = cr_head(implode('', cr_get(function (ClientRequest $r) {
        $r->sendResponseHeaders(200, ['x-a' => '1']);
        $r->end();
    })));
    expect([$empty['content-length'], $empty['x-a']])->toBe(['0', '1']);

    $sized = cr_head(implode('', cr_get(function (ClientRequest $r) {
        $r->sendResponseHeaders(200, ['content-length' => '0']);
        $r->end();
    })));
    expect($sized['content-length'])->toBe('0');
});

test('the held head goes out with the first body bytes in one write', function () {
    $packets = cr_get(function (ClientRequest $r) {
        $r->sendResponseHeaders(200, ['content-length' => '10']);
        $r->write('hello');
        phasync::sleep(0.02);
        $r->write('world');
    });

    expect($packets)->toHaveCount(2);
    expect($packets[0])->toStartWith('HTTP/1.1 200 OK')->toEndWith("\r\n\r\nhello");
    expect($packets[1])->toBe('world');
});

test('flush() puts the head on the wire before any body', function () {
    $packets = cr_get(function (ClientRequest $r) {
        $r->sendResponseHeaders(200);
        $r->flush();
        phasync::sleep(0.02);
        $r->write('data');
    });

    expect($packets)->toHaveCount(3);
    expect($packets[0])->toEndWith("Transfer-Encoding: chunked\r\n\r\n");
    expect([$packets[1], $packets[2]])->toBe(["4\r\ndata\r\n", "0\r\n\r\n"]);
});

test('write(\'\') does nothing: not a head, not a chunk', function () {
    $raw = implode('', cr_get(function (ClientRequest $r) {
        $r->write('');
        expect($r->headersSent())->toBeFalse();
        $r->sendResponseHeaders(404, ['content-length' => '1']);
        $r->write('');
        $r->write('x');
    }));

    expect($raw)->toStartWith('HTTP/1.1 404 Not Found')->toEndWith("\r\n\r\nx");
});

test('a 1xx response goes out at once, and may be repeated, before the final head', function () {
    $packets = cr_get(function (ClientRequest $r) {
        $r->sendResponseHeaders(103, ['link' => '</a.css>; rel=preload']);
        $r->sendResponseHeaders(103, ['link' => '</b.js>; rel=preload']);
        expect($r->headersSent())->toBeFalse();
        phasync::sleep(0.02);
        $r->sendResponseHeaders(200, ['content-length' => '2']);
        $r->write('ok');
    });

    expect($packets)->toHaveCount(3);
    expect($packets[0])->toStartWith('HTTP/1.1 103 Early Hints')->toContain('a.css');
    expect($packets[1])->toStartWith('HTTP/1.1 103 Early Hints')->toContain('b.js');
    expect($packets[2])->toStartWith('HTTP/1.1 200 OK')->toEndWith('ok');
});

test('after a 101 the ClientRequest is the connection: what followed the Upgrade request is read first', function () {
    $seen    = [];
    $handler = function (ClientRequest $r) use (&$seen) {
        $r->sendResponseHeaders(101, ['upgrade' => 'echo', 'connection' => 'Upgrade']);
        $seen[] = $r->read(); // sent behind the request's head, in the same packet
        $r->write('pong');
        $seen[] = $r->read();
        $r->write('!');
    };
    $request = "GET / HTTP/1.1\r\nHost: t\r\nUpgrade: echo\r\nConnection: Upgrade\r\n\r\nping";
    $packets = native_serve_packets($handler, $request, true);

    expect($packets[0])->toStartWith('HTTP/1.1 101 Switching Protocols')->toContain("echo");
    expect($seen)->toBe(['ping', '']);
    expect(implode('', $packets))->toContain('pong');
});

test('a 101 to a request that did not ask for an upgrade fails, as a 500', function () {
    $raw = implode('', cr_get(function (ClientRequest $r) {
        $r->sendResponseHeaders(101, ['upgrade' => 'echo', 'connection' => 'Upgrade']);
    }));

    expect($raw)->toStartWith('HTTP/1.1 500');
});

test('sendFile() sends a file, a part of it, and nothing for HEAD', function () {
    $file = function (): mixed {
        $fp = fopen('php://temp/maxmemory:0', 'r+');
        fwrite($fp, '0123456789');
        rewind($fp);

        return $fp;
    };
    $whole = implode('', cr_get(function (ClientRequest $r) use ($file) {
        $r->sendResponseHeaders(200, ['content-length' => '10']);
        $r->sendFile($file());
    }));
    expect($whole)->toEndWith("\r\n\r\n0123456789");

    $part = implode('', cr_get(function (ClientRequest $r) use ($file) {
        $r->sendResponseHeaders(206, ['content-length' => '4']);
        $r->sendFile($file(), 3, 4);
    }));
    expect($part)->toEndWith("\r\n\r\n3456");

    $head = implode('', cr_get(function (ClientRequest $r) use ($file) {
        $r->sendResponseHeaders(200, ['content-length' => '10']);
        $r->sendFile($file());
    }, method: 'HEAD'));
    expect($head)->toEndWith("Content-Length: 10\r\n\r\n")->and(cr_head($head)['content-length'])->toBe('10');

    // Without a length, chunked
    $chunked = implode('', cr_get(function (ClientRequest $r) use ($file) {
        $r->sendFile($file(), 8);
    }));
    expect($chunked)->toEndWith("\r\n\r\n2\r\n89\r\n0\r\n\r\n");
});

test('a file shorter than its content-length ends the connection after what there was', function () {
    $fp = fopen('php://temp/maxmemory:0', 'r+');
    fwrite($fp, 'short');
    rewind($fp);
    $handler = function (ClientRequest $r) use ($fp) {
        $r->sendResponseHeaders(200, ['content-length' => '10']);
        $r->sendFile($fp);
    };
    $raw = implode('', native_serve_packets($handler, str_repeat("GET / HTTP/1.1\r\nHost: t\r\n\r\n", 2), true));

    expect($raw)->toEndWith("\r\n\r\nshort");
    expect(substr_count($raw, 'HTTP/1.1 200'))->toBe(1); // the second request was never answered
});

test('end() takes trailers on a chunked response, and refuses them anywhere else', function () {
    $raw = implode('', cr_get(function (ClientRequest $r) {
        $r->sendResponseHeaders(200, ['trailer' => 'x-sum']);
        $r->write('abc');
        $r->end(['x-sum' => '6']);
    }));
    expect($raw)->toContain('x-sum')->toEndWith("3\r\nabc\r\n0\r\nx-sum: 6\r\n\r\n");

    $errors = [];
    $try    = function (ClientRequest $r) use (&$errors) {
        try {
            $r->end(['x-sum' => '6']);
        } catch (LogicException $e) {
            $errors[] = 'refused';
            $r->end();
        }
    };
    cr_get(function (ClientRequest $r) use ($try) {
        $r->sendResponseHeaders(200, ['content-length' => '0']);
        $try($r);
    });
    cr_get($try, version: '1.0');
    cr_get(function (ClientRequest $r) use ($try) {
        $r->sendResponseHeaders(204);
        $try($r);
    });

    expect($errors)->toBe(['refused', 'refused', 'refused']);
});

test('a handler that throws before the head is committed is answered with a 500, after it the connection is aborted', function () {
    $before = implode('', cr_get(function (ClientRequest $r) {
        $r->sendResponseHeaders(200, ['x-held' => '1']);
        throw new DomainException('boom');
    }));
    expect($before)->toStartWith('HTTP/1.1 500 Internal Server Error')->not->toContain('x-held');

    $handler = function (ClientRequest $r) {
        $r->sendResponseHeaders(200, ['content-length' => '10']);
        $r->write('half');
        throw new DomainException('boom');
    };
    $raw = implode('', native_serve_packets($handler, str_repeat("GET / HTTP/1.1\r\nHost: t\r\n\r\n", 2), true));
    expect($raw)->toStartWith('HTTP/1.1 200 OK')->toEndWith("\r\n\r\nhalf");
    expect(substr_count($raw, 'HTTP/1.1'))->toBe(1);
});

test('every response has a date, and a server unless the handler sets one; error responses too', function () {
    $plain = cr_head(implode('', cr_get(fn (ClientRequest $r) => $r->end())));
    expect($plain['date'])->toMatch('/^\w{3}, \d{2} \w{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/')->and($plain['server'])->toBe('Swerve');

    $own = cr_head(implode('', cr_get(function (ClientRequest $r) {
        $r->sendResponseHeaders(200, ['server' => 'mine', 'date' => 'ignored']);
        $r->end();
    })));
    expect($own['server'])->toBe('mine');

    $error = cr_head(implode('', cr_get(function () {
        throw new DomainException('boom');
    })));
    expect($error['status'])->toBe('HTTP/1.1 500 Internal Server Error')->and($error['date'])->toBe($plain['date']);

    $refused = cr_head(implode('', native_serve_packets(fn (ClientRequest $r) => $r->end(), "NOT HTTP\r\n\r\n", true)));
    expect($refused['status'])->toStartWith('HTTP/1.1 400')->and($refused)->toHaveKeys(['date', 'server']);
});

test('a handler\'s transfer-encoding of chunked is honoured, any other is a 500', function () {
    $chunked = implode('', cr_get(function (ClientRequest $r) {
        $r->sendResponseHeaders(200, ['transfer-encoding' => 'chunked']);
        $r->write('abc');
    }));
    expect($chunked)->toContain("Transfer-Encoding: chunked\r\n")->toEndWith("3\r\nabc\r\n0\r\n\r\n");

    $gzip = implode('', cr_get(function (ClientRequest $r) {
        $r->sendResponseHeaders(200, ['transfer-encoding' => 'gzip, chunked']);
        $r->write('abc');
    }));
    expect($gzip)->toStartWith('HTTP/1.1 500');
});

test('the request is described as it was sent', function () {
    $seen = [];
    native_serve_packets(function (ClientRequest $r) use (&$seen) {
        $seen = [$r->getMethod(), $r->getTarget(), $r->getProtocolVersion(), $r->getScheme(), $r->getRequestHeaders()];
        $r->end();
    }, "POST /a%20b?x=1 HTTP/1.1\r\nHost: t\r\nX-Multi: 1\r\nx-multi: 2\r\nContent-Length: 0\r\n\r\n", true);

    expect($seen)->toBe(['POST', '/a%20b?x=1', '1.1', 'http', ['host' => ['t'], 'x-multi' => ['1', '2'], 'content-length' => ['0']]]);
});

test('an application that returns a bare closure, or a RequestHandler of a callable that is no closure, stops the master with exit code 2', function (string $fixture, string $message) {
    [$process, , $log] = swerve_start(fixture: $fixture, wait: false);
    [$code]            = swerve_wait($process, 3);

    expect($code)->toBe(2);
    expect(file_get_contents($log))->toContain($message);
})->with([
    'a closure'          => ['bad-closure.php', 'must return a Swerve\RequestHandler'],
    'a callable object'  => ['bad-callable.php', 'must be of type Closure'],
]);
