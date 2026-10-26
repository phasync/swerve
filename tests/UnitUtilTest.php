<?php

/*
 * Pure unit tests of the command line (Args, Option, Flag, Argument, Address) and of System's
 * helpers that need no server or child process.
 */

use Swerve\Util\SealedObject;
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

test('SealedObject: nested objects are sealed too, the same instance every time, and reading looks like stdClass', function () {
    $message = SealedObject::seal(json_decode('{"end":true,"user":{"name":"a","tags":[{"t":1},2]},"empty":{}}'));
    expect($message)->toBeInstanceOf(SealedObject::class);
    expect($message->end)->toBeTrue();
    expect(isset($message->end))->toBeTrue();
    expect(isset($message->missing))->toBeFalse();
    expect($message->missing ?? 'default')->toBe('default');
    expect($message->user)->toBeInstanceOf(SealedObject::class);
    expect($message->user)->toBe($message->user);
    expect($message->user->name)->toBe('a');
    expect($message->user->tags[0])->toBeInstanceOf(SealedObject::class);
    expect($message->user->tags[0]->t)->toBe(1);
    expect($message->user->tags[1])->toBe(2);
    expect($message->empty)->toBeInstanceOf(SealedObject::class); // {} stays an object, unlike with assoc = true
    expect(array_keys(iterator_to_array($message)))->toBe(['end', 'user', 'empty']);
    expect(json_encode($message))->toBe('{"end":true,"user":{"name":"a","tags":[{"t":1},2]},"empty":{}}');
});

test('SealedObject: nothing can be attached, changed or removed, also under the names of its own members', function () {
    $message = SealedObject::seal(json_decode('{"a":1,"inner":"x","sealed":"y"}'));
    expect($message->inner)->toBe('x');
    expect($message->sealed)->toBe('y');
    foreach (['a', 'new', 'inner', 'sealed'] as $name) {
        expect(fn () => $message->$name = 2)->toThrow(LogicException::class);
        expect(function () use ($message, $name) { unset($message->$name); })->toThrow(LogicException::class);
    }
    expect($message->a)->toBe(1);
    // What json_encode() takes is a copy
    $copy = $message->jsonSerialize();
    $copy->a = 2;
    expect($message->a)->toBe(1);
});

test('SealedObject: a list at the top keeps its objects sealed, scalars pass through', function () {
    $list = SealedObject::seal(json_decode('[{"a":1},[{"b":2}],"s",3]'));
    expect($list[0])->toBeInstanceOf(SealedObject::class);
    expect($list[1][0]->b)->toBe(2);
    expect([$list[2], $list[3]])->toBe(['s', 3]);
    expect(SealedObject::seal('{}'))->toBe('{}');
});

test('SealedObject: reading a missing property warns, as stdClass does', function () {
    $message = SealedObject::seal(json_decode('{"a":1}'));
    $seen = null;
    set_error_handler(function (int $no, string $str) use (&$seen) { $seen = $str; return true; });
    try {
        expect($message->nope)->toBeNull();
    } finally {
        restore_error_handler();
    }
    expect($seen)->toContain('Undefined property')->toContain('$nope');
});
