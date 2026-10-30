<?php

// Findings from running Laravel through Octane's own Worker/ApplicationFactory on swerve 0.2
// in serialized mode (`while ($request = Swerve::accept(...)) { phasync::await($request); }`),
// with a PSR-7 closure handler. Fixtures live in tests/Fixtures/serialized-mode/.

/**
 * Starts bin/swerve.php on a fixture under tests/Fixtures/serialized-mode/, and waits until it
 * answers GET /hello (every fixture here must answer it, so start-up can be detected without
 * relying on the behaviour under test).
 *
 * @return array{0: resource, 1: string} the process and its address
 */
function serialized_start(string $fixture, int $workers = 1): array
{
    return native_start(__DIR__."/Fixtures/serialized-mode/$fixture", [], $workers);
}

// #107
it('sends the PSR-7 response\'s own Content-Type, not the default, when a tail coroutine filling its body calls flush()', function () {
    // The handler returns at once with a phasync\Psr\UnbufferedStream body; a phasync::go()
    // coroutine fills it afterwards and calls flush() once per chunk -- the same thing
    // Symfony's StreamedResponse callback does on every write, so any Laravel/Symfony app
    // streaming a response through Octane's worker model on swerve hits this.
    [$process, $addr] = serialized_start('flush-during-tail-stream.php');

    $conn = native_connect($addr);
    fwrite($conn, "GET / HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
    $response = native_read_response($conn);
    fclose($conn);

    expect($response)->not->toBeNull();
    // The PSR-7 response was built with ['content-type' => ['text/plain']]. flush() inside the
    // tail coroutine commits a head of its own first (with PHP's default Content-Type) before
    // Swerve\Psr\Psr7::send() gets to send the one from the response object, so the client sees
    // "text/html; charset=UTF-8" instead, and swerve logs "headers already sent" warnings.
    expect($response['headers']['content-type'] ?? null)->toBe('text/plain');
    expect($response['body'])->toBe("one\ntwo\nthree\n");

    native_stop($process);
});

it('finishes a PSR-7 response whose body a tail coroutine is still streaming, across a SIGHUP reload', function () {
    // README.md: on a reload, the old worker "finish[es] the requests in flight" before any
    // coroutine still running gets a WorkerStoppingException. The handler already returned (the
    // PSR-7 response it gave back is a tail coroutine's UnbufferedStream, still being filled) --
    // this checks that such a request still counts as "in flight", not as expendable background
    // work cut by the reload.
    [$process, $addr] = serialized_start('tail-stream-survives-reload.php');

    $conn = native_connect($addr);
    fwrite($conn, "GET / HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
    usleep(150_000); // the first chunk or two went out; the body is still streaming
    posix_kill(proc_get_status($process)['pid'], SIGHUP);
    $response = native_read_response($conn);
    fclose($conn);

    expect($response)->not->toBeNull();
    expect($response['complete'])->toBeTrue();
    expect($response['body'])->toBe("one\ntwo\nthree\nfour\nfive\n");

    native_stop($process);
});
