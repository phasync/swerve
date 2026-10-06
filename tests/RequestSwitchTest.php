<?php

/*
 * Swerve::onRequestSwitch(): told when the event loop switches from a coroutine of one request
 * to a coroutine of a different one, so a framework can keep process-wide PHP state
 * (setlocale(), ...) as the request's own. Implemented by the request's phasync context
 * (Swerve\Util\SwitchAwareLoggingContext), which phasync\Context\SwitchAwareInterface drives;
 * see Swerve\Util\RequestSwitch.
 */

use Swerve\ClientRequest;
use Swerve\Http\HttpConnection;
use Swerve\Swerve;
use Swerve\Util\LoggingContext;
use Swerve\Util\RequestSwitch;

/** Swerve::onRequestSwitch() is meant to be called once at boot: tests undo it afterwards. */
afterEach(function () {
    RequestSwitch::$resumeListeners  = [];
    RequestSwitch::$suspendListeners = [];
    Swerve::setLog(new Psr\Log\NullLogger());
});

/**
 * Serve each of $handlers concurrently, on a connection of its own, and wait for all of them to
 * finish; like serve_in_process() in HttpStreamsTest.php, for several connections at once. The
 * client side sends one GET and never reads the response: these tests look at what the handler
 * observed, not at the bytes on the wire.
 *
 * @param list<Closure(ClientRequest):void> $handlers
 */
function serve_switch_test(array $handlers): void
{
    phasync::run(function () use ($handlers) {
        $fibers = [];
        $conns  = [];
        foreach ($handlers as $handler) {
            [$server, $conn] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            stream_set_blocking($server, false);
            stream_set_blocking($conn, false);
            $fibers[] = phasync::go((new HttpConnection(new phasync\Net\StreamDuplex($server, '127.0.0.1:1'), $handler, new Psr\Log\NullLogger(), null))->serve(...));
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

/** A logger keeping "level: message" lines, see streams_logger() in HttpStreamsTest.php. */
function switch_logger(): Psr\Log\AbstractLogger
{
    return new class () extends Psr\Log\AbstractLogger {
        public array $lines = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $exception     = $context['exception'] ?? null;
            $this->lines[] = "$level: $message" . ($exception ? ' ' . $exception->getMessage() : '');
        }
    };
}

test('a single request that never shares a worker tick with another gets a resume and no suspend', function () {
    $events = [];
    Swerve::onRequestSwitch(
        resume: function (ClientRequest $r) use (&$events) {
            $events[] = 'resume';
        },
        suspend: function (ClientRequest $r) use (&$events) {
            $events[] = 'suspend';
        },
    );

    serve_switch_test([
        function (ClientRequest $r) {
            phasync::sleep(0.01); // nothing else ever runs: no coroutine of a different request to pair with
            phasync::sleep(0.01);
            $r->end();
        },
    ]);

    // Once something is registered, a request's context is live from its own first coroutine:
    // one resume, to make it so. Nothing ever takes it over, so it is never suspended.
    expect($events)->toBe(['resume']);
});

test('without a registration, a request gets a plain LoggingContext, never a switch-aware one', function () {
    $classes = [];

    serve_switch_test([
        function (ClientRequest $r) use (&$classes) {
            $classes[] = \get_class(phasync::getContext());
            $r->end();
        },
    ]);

    expect($classes)->toBe([LoggingContext::class]);
});

test('a request\'s own coroutines share its context: many child switches are not a request switch', function () {
    // Raw (request, event) pairs, filtered below once $request is known: the very first
    // resume() of a run, which makes a request's context live, fires before its handler's
    // first line runs, so $request isn't known yet at that exact moment.
    $raw     = [];
    $request = null;
    Swerve::onRequestSwitch(
        resume: function (ClientRequest $r) use (&$raw) {
            $raw[] = [$r, 'resume'];
        },
        suspend: function (ClientRequest $r) use (&$raw) {
            $raw[] = [$r, 'suspend'];
        },
    );

    serve_switch_test([
        function (ClientRequest $r) use (&$request) {
            $request  = $r;
            $children = [];
            for ($i = 0; $i < 4; ++$i) {
                $children[] = phasync::go(function () {
                    phasync::sleep(0.005);
                    phasync::sleep(0.005);
                });
            }
            foreach ($children as $child) {
                phasync::await($child);
            }
            $r->end();
        },
    ]);

    $events = \array_column(\array_filter($raw, static fn ($e) => $e[0] === $request), 1);

    // Entering this request's context (once something is registered) makes it live: one
    // resume. Nothing ever takes it over, so it is never suspended, no matter how many times
    // its children switched among themselves.
    expect($events)->toBe(['resume']);
});

test('two interleaved requests keep their own setlocale() through the hook, after every sleep', function () {
    if (false === @setlocale(LC_ALL, 'C.UTF-8') || false === @setlocale(LC_ALL, 'C')) {
        test()->markTestSkipped('C and C.UTF-8 locales are not both available');
    }

    $locales = new WeakMap();
    Swerve::onRequestSwitch(
        resume: function (ClientRequest $r) use ($locales) {
            setlocale(LC_ALL, $locales[$r] ?? 'C');
        },
        suspend: function (ClientRequest $r) use ($locales) {
            $locales[$r] = setlocale(LC_ALL, 0);
        },
    );

    $seenA = [];
    $seenB = [];

    serve_switch_test([
        function (ClientRequest $r) use (&$seenA) {
            phasync::getContext(); // materializes this request's context, so its coroutine's
            setlocale(LC_ALL, 'C.UTF-8');   // own sleeps below go through the hook like any other
            for ($i = 0; $i < 3; ++$i) {
                phasync::sleep(0.01);
                $seenA[] = setlocale(LC_ALL, 0);
            }
            $r->end();
        },
        function (ClientRequest $r) use (&$seenB) {
            phasync::getContext();
            setlocale(LC_ALL, 'C');
            for ($i = 0; $i < 3; ++$i) {
                phasync::sleep(0.01);
                $seenB[] = setlocale(LC_ALL, 0);
            }
            $r->end();
        },
    ]);

    expect($seenA)->toBe(['C.UTF-8', 'C.UTF-8', 'C.UTF-8']);
    expect($seenB)->toBe(['C', 'C', 'C']);
});

test('several registrations run in registration order on resume, reverse order on suspend', function () {
    $raw    = [];
    $target = null;
    for ($i = 1; $i <= 3; ++$i) {
        Swerve::onRequestSwitch(
            resume: function (ClientRequest $r) use (&$raw, $i) {
                $raw[] = [$r, "resume$i"];
            },
            suspend: function (ClientRequest $r) use (&$raw, $i) {
                $raw[] = [$r, "suspend$i"];
            },
        );
    }

    serve_switch_test([
        function (ClientRequest $r) use (&$target) {
            $target = $r; // entering this request's context (one resume) already happened by now
            $r->end();
        },
        function (ClientRequest $r) {
            // Entering the request above's own context is the one resume that made it live, so
            // entering this one is its one suspend: nothing else ever runs after this one, so
            // the request above is never taken live again.
            $r->end();
        },
    ]);

    $order = \array_column(\array_filter($raw, static fn ($e) => $e[0] === $target), 1);

    expect($order)->toBe(['resume1', 'resume2', 'resume3', 'suspend3', 'suspend2', 'suspend1']);
});

test('an exception from a resume or a suspend callback is logged, and the request is unaffected', function (string $which) {
    $logger = switch_logger();
    Swerve::setLog($logger);
    Swerve::onRequestSwitch(
        resume: function (ClientRequest $r) use ($which) {
            if ('resume' === $which) {
                throw new RuntimeException('resume callback failed');
            }
        },
        suspend: function (ClientRequest $r) use ($which) {
            if ('suspend' === $which) {
                throw new RuntimeException('suspend callback failed');
            }
        },
    );

    $finished = [];
    serve_switch_test([
        function (ClientRequest $r) use (&$finished) {
            phasync::getContext();
            phasync::sleep(0.02);
            $finished[] = 'a';
            $r->end();
        },
        function (ClientRequest $r) use (&$finished) {
            phasync::getContext();
            phasync::sleep(0.02);
            $finished[] = 'b';
            $r->end();
        },
    ]);

    expect($finished)->toBe(['a', 'b']);
    expect(implode("\n", $logger->lines))->toContain("$which callback failed");
})->with(['resume', 'suspend']);

test('through a real swerve.php, concurrent requests keep their own setlocale() the same way SwapFixture keeps a static property', function () {
    if (false === @setlocale(LC_ALL, 'C.UTF-8') || false === @setlocale(LC_ALL, 'C')) {
        test()->markTestSkipped('C and C.UTF-8 locales are not both available');
    }

    [$master, $addr] = native_start(workers: 1, env: ['SWERVE_TEST_LOCALE_SWITCH' => '1']);
    try {
        $conns = [];
        foreach (['C' => 'a', 'C.UTF-8' => 'b'] as $locale => $tag) {
            $conns[$tag] = native_connect($addr);
            fwrite($conns[$tag], "GET /locale-switch?v=$locale&ms=100 HTTP/1.1\r\nHost: t\r\n\r\n");
        }
        expect(native_read_response($conns['a'])['body'] ?? null)->toBe('C');
        expect(native_read_response($conns['b'])['body'] ?? null)->toBe('C.UTF-8');
    } finally {
        native_stop($master);
    }
});

test('a callback that tries to suspend is abandoned and logged as a LogicException, the request is unaffected', function () {
    $logger = switch_logger();
    Swerve::setLog($logger);
    Swerve::onRequestSwitch(
        resume: function (ClientRequest $r) {
            phasync::sleep(0.01); // a callback must not wait
        },
        suspend: function (ClientRequest $r) {
        },
    );

    $finished = [];
    serve_switch_test([
        function (ClientRequest $r) use (&$finished) {
            phasync::getContext();
            $finished[] = 'a';
            $r->end();
        },
    ]);

    expect($finished)->toBe(['a']);
    expect(implode("\n", $logger->lines))->toContain('must not suspend');
});
