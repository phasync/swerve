<?php

/*
 * Swerve::onShutdown() and awaitShutdown(): told that this worker must now close its connections.
 */

use Swerve\Swerve;
use Swerve\Util\Topics;

/** The process goes on after a test that drained it. */
function shutdown_reset(): void
{
    Topics::$draining = false;
    Topics::$closed   = false;
}

test('onShutdown() callbacks run when the worker shuts down, each in a coroutine of its own', function () {
    $got = phasync::run(function () {
        $log = [];
        Swerve::onShutdown(static function () use (&$log) {
            phasync::sleep(0.05); // a slow one does not delay the others
            $log[] = 'slow';
        });
        Swerve::onShutdown(static function () use (&$log) {
            $log[] = 'fast';
        });
        phasync::sleep(0.01);
        $log[] = 'before';
        Topics::drain();
        phasync::sleep(0.2);

        return $log;
    });
    shutdown_reset();

    expect($got)->toBe(['before', 'fast', 'slow']);
});

test('onShutdown() callbacks run once only, and one registered after the shutdown runs at once', function () {
    $got = phasync::run(function () {
        $log = [];
        Swerve::onShutdown(static function () use (&$log) {
            $log[] = 'first';
        });
        Topics::drain();
        Topics::drain();
        Swerve::onShutdown(static function () use (&$log) {
            $log[] = 'late';
        });
        phasync::sleep(0.05);

        return $log;
    });
    shutdown_reset();

    expect($got)->toBe(['first', 'late']);
});

test('an exception in an onShutdown() callback is logged and does not stop the others', function () {
    $messages = [];
    Swerve::setLog(new class($messages) extends Psr\Log\AbstractLogger {
        public function __construct(private array &$messages)
        {
        }

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->messages[] = "$level: " . $message . ' ' . ($context['exception'] ?? '');
        }
    });
    $got = phasync::run(function () {
        $log = [];
        Swerve::onShutdown(static fn () => throw new RuntimeException('callback one failed'));
        Swerve::onShutdown(static function () use (&$log) {
            $log[] = 'two';
        });
        Swerve::onShutdown(static fn () => throw new RuntimeException('callback three failed'));
        Swerve::onShutdown(static function () use (&$log) {
            $log[] = 'four';
        });
        Topics::drain();
        phasync::sleep(0.05);

        return $log;
    });
    shutdown_reset();
    Swerve::setLog(new Psr\Log\NullLogger());

    expect($got)->toBe(['two', 'four']);
    expect(implode("\n", $messages))->toContain('callback one failed')->toContain('callback three failed');
});

test('a callback of a request that ended never runs, and what it holds is freed before the shutdown', function () {
    $got = phasync::run(function () {
        $probes = [];
        $ran    = [];
        for ($i = 0; $i < 3; ++$i) {
            phasync::withContext(function () use (&$probes, &$ran, $i) {
                $probe    = new stdClass();
                $probes[] = WeakReference::create($probe);
                Swerve::onShutdown(static function () use ($probe, &$ran, $i) {
                    $ran[] = $i;
                });
                phasync::sleep(0.01);
            }, new stdClass());
        }
        phasync::sleep(0.7); // phasync collects cycles half a second after a coroutine ends
        gc_collect_cycles();
        $alive = count(array_filter($probes, static fn (WeakReference $r) => null !== $r->get()));
        Topics::drain();
        phasync::sleep(0.05);

        return [$alive, $ran];
    });
    shutdown_reset();

    expect($got)->toBe([0, []]);
});

test('a callback of a coroutine that is still running does run, also when another request ended meanwhile', function () {
    $got = phasync::run(function () {
        $ran = [];
        $ctx = static function (string $name, float $for) use (&$ran) {
            return phasync::go(function () use ($name, $for, &$ran) {
                phasync::withContext(function () use ($name, $for, &$ran) {
                    Swerve::onShutdown(static function () use ($name, &$ran) {
                        $ran[] = $name;
                    });
                    phasync::sleep($for);
                }, new stdClass());
            });
        };
        $long = $ctx('long', 0.5);
        $ctx('short', 0.01);
        phasync::sleep(0.1);
        Topics::drain();
        phasync::sleep(0.05);
        phasync::await($long);

        return $ran;
    });
    shutdown_reset();

    expect($got)->toBe(['long']);
});

test('awaitShutdown() waits for the shutdown, or the timeout', function () {
    $got = phasync::run(function () {
        $start   = microtime(true);
        $timeout = Swerve::awaitShutdown(0.05);
        $waited  = microtime(true) - $start;
        $waiter  = phasync::go(static fn () => Swerve::awaitShutdown());
        phasync::sleep(0.01);
        Topics::drain();
        $after = Swerve::awaitShutdown(0.01); // already shut down: at once

        return [$timeout, $waited >= 0.04, phasync::await($waiter), $after];
    });
    shutdown_reset();

    expect($got)->toBe([false, true, true, true]);
});

test('a WebSocket is told to say goodbye when its worker is stopped, and the callbacks that throw do not stop it', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    $conn = ws_connect($addr, '/websocket-shutdown?throw=1');
    ws_read($conn); // the pid
    ws_send($conn, 1, 'ping');
    expect(ws_read($conn))->toBe([1, 'ping']);
    swerve_signal($process, SIGTERM);
    expect(ws_read($conn))->toBe([8, pack('n', 1001)]);
    expect(swerve_wait($process, 5)[0])->toBe(0);
    expect(file_get_contents($log))->toContain('onShutdown callback ran')->toContain('the shutdown callback failed');
});

test('the callbacks of requests that ended never run, and what they held is freed', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        for ($i = 0; $i < 5; ++$i) {
            expect(probe($addr, '/shutdown-plain'))->toBe('registered');
        }
        expect(probe($addr, '/shutdown-probes', 3.0))->toBe('0');
    } finally {
        native_stop($process);
    }
    expect(file_get_contents($log))->not->toContain('a request that ended ran');
});
