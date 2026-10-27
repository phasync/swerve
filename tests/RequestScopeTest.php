<?php

/*
 * Each request runs in a Scope of its own (its identity) and in the frame of a nested
 * phasync::run() (its lifetime), see HttpConnection::serve(). State kept per request hangs on a
 * WeakMap keyed by Scope::getCurrent(); process state (a locale, a framework's static) is
 * swapped per request by a phasync::onScopeChange() hook.
 */

use Swerve\ClientRequest;
use Swerve\Http\HttpConnection;

/** onScopeChange() hooks stay for the life of the event loop: each test gets a loop of its own. */
beforeEach(fn () => (new ReflectionProperty(phasync::class, 'driver'))->setValue(null, null));
afterEach(fn () => (new ReflectionProperty(phasync::class, 'driver'))->setValue(null, null));

/**
 * Serve each of $handlers concurrently, on a connection of its own, and wait for all of them to
 * finish. The client side sends one GET and never reads the response: these tests look at what
 * the handler observed, not at the bytes on the wire.
 *
 * @param list<Closure(ClientRequest):void> $handlers
 */
function serve_scope_test(array $handlers, ?Psr\Log\LoggerInterface $logger = null): void
{
    phasync::run(function () use ($handlers, $logger) {
        $fibers = [];
        $conns  = [];
        foreach ($handlers as $handler) {
            [$server, $conn] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            stream_set_blocking($server, false);
            stream_set_blocking($conn, false);
            $fibers[] = phasync::go((new HttpConnection(new phasync\Net\StreamDuplex($server, '127.0.0.1:1'), $handler, $logger ?? new Psr\Log\NullLogger(), null))->serve(...));
            $conns[]  = $conn;
            fwrite($conn, "GET / HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
            stream_socket_shutdown($conn, STREAM_SHUT_WR); // nothing more to send: the server's linger ends at once
        }
        foreach ($fibers as $fiber) {
            phasync::await($fiber);
        }
        foreach ($conns as $conn) {
            fclose($conn);
        }
    });
}

test('state kept in a WeakMap keyed by the request\'s scope is the request\'s own, and is freed with it', function () {
    $state = new WeakMap();
    $seen  = [];
    $handler = function (ClientRequest $r) use ($state, &$seen) {
        $state[Scope::getCurrent()] = $r->getTarget() . ' ' . spl_object_id($r);
        $mine                       = $state[Scope::getCurrent()];
        phasync::sleep(0.01);
        $seen[] = $state[Scope::getCurrent()] === $mine;
        $r->end();
    };
    serve_scope_test([$handler, $handler]);
    gc_collect_cycles();

    expect($seen)->toBe([true, true]);
    expect(count($state))->toBe(0);
});

test('the coroutines a request starts are in its frame: it waits for them, and their unawaited failures are logged with the request', function () {
    $logger = new class () extends Psr\Log\AbstractLogger {
        public array $lines = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->lines[] = strtr("$level: $message", ['{request}' => $context['request'] ?? '', '{exception}' => ($context['exception'] ?? null)?->getMessage() ?? '']);
        }
    };
    $log     = [];
    $handler = function (ClientRequest $r) use (&$log) {
        phasync::go(function () use (&$log) {
            phasync::sleep(0.02);
            $log[] = 'child ended';

            throw new RuntimeException('child failed');
        });
        $r->end();
        $log[] = 'handler returned';
    };
    serve_scope_test([$handler], $logger);

    expect($log)->toBe(['handler returned', 'child ended']);
    expect($logger->lines)->toBe(['error: A coroutine of GET / failed: child failed']);
});
