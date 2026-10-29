<?php

/*
 * Pure unit tests of the command line (Args, Option, Flag, Argument, Address) and of System's
 * helpers that need no server or child process.
 */

use Swerve\Util\System;

test('System counts at least one CPU and reports whether ext-sockets is usable', function () {
    expect(System::getCPUCount())->toBeGreaterThanOrEqual(1);
    expect(System::hasSockets())->toBe(function_exists('socket_create') && defined('SOCK_CLOEXEC'));
});

test('System names signals, and SIG? for an unknown one', function () {
    expect(System::signalName(0))->toBe('SIG?');
    expect(System::signalName(9999))->toBe('SIG?');
    expect(System::signalName(-1))->toBe('SIG?');
})->group('signals');

test('System names the signals PHP defines constants for', function () {
    expect(System::signalName(SIGTERM))->toBe('SIGTERM');
    expect(System::signalName(SIGKILL))->toBe('SIGKILL');
    expect(System::signalName(SIGINT))->toBe('SIGINT');
    expect(System::signalName(SIGUSR1))->toBe('SIGUSR1');
})->skip(!defined('SIGTERM'), 'needs ext-pcntl');

test('System makes a connected socket pair', function () {
    [$a, $b] = System::socketPair();
    try {
        fwrite($a, "ping\n");
        expect(fgets($b))->toBe("ping\n");
        fwrite($b, "pong\n");
        expect(fgets($a))->toBe("pong\n");
    } finally {
        fclose($a);
        fclose($b);
    }
});
