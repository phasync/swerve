<?php

use Swerve\Swerve;
use Swerve\Util\Topics;

/*
 * Swerve::publishRaw(): bytes that publish() cannot carry (they need not be valid UTF-8), sent
 * unchanged to Swerve::subscribe(), in this process (no master), then across the workers of a
 * cluster. See PubSubTest for publish()/subscribe() itself.
 */

test('without a master, a subscriber gets exactly the bytes published, invalid UTF-8 included', function () {
    $bytes = \implode('', \array_map('chr', \range(0, 255))); // every byte value once

    $got = phasync::run(function () use ($bytes) {
        $subscription = Swerve::subscribe('frames');
        Swerve::publishRaw('frames', $bytes);
        foreach ($subscription as $message) {
            return $message;
        }
    });

    expect($got)->toBe($bytes);
});

test('a raw string that looks like JSON arrives as that exact string, not decoded', function () {
    $got = phasync::run(function () {
        $subscription = Swerve::subscribe('t');
        Swerve::publishRaw('t', '{}');
        Swerve::publishRaw('t', '"x"');
        Swerve::publishRaw('t', '');
        $got = [];
        foreach ($subscription as $message) {
            $got[] = $message;
            if (3 === \count($got)) {
                return $got;
            }
        }
    });

    expect($got)->toBe(['{}', '"x"', '']); // an empty raw message is allowed
});

test('publish() and publishRaw() on one topic arrive in order, each in its own shape', function () {
    $got = phasync::run(function () {
        $subscription = Swerve::subscribe('t');
        Swerve::publish('t', ['n' => 1]);
        Swerve::publishRaw('t', "\xFF\x00");
        Swerve::publish('t', 'plain');
        Swerve::publishRaw('t', '{}');
        $got = [];
        foreach ($subscription as $message) {
            $got[] = $message;
            if (4 === \count($got)) {
                return $got;
            }
        }
    });

    expect($got[0]->n)->toBe(1);        // publish(): an object with keys is a SealedObject
    expect($got[1])->toBe("\xFF\x00");  // publishRaw(): the exact bytes
    expect($got[2])->toBe('plain');     // publish(): a string stays a string
    expect($got[3])->toBe('{}');        // publishRaw(): not decoded, even though it looks like JSON
});

test('publishRaw() refuses a topic of 0 or over 255 bytes, a topic starting with "\0", and bytes over 128 KiB', function (string $topic, int $size) {
    expect(static fn () => Swerve::publishRaw($topic, \str_repeat('x', $size)))->toThrow(InvalidArgumentException::class);
})->with([
    'empty topic'    => ['', 1],
    'long topic'     => [\str_repeat('t', 256), 1],
    'internal topic' => ["\0t", 1],
    'large message'  => ['t', Topics::MAX_MESSAGE + 1],
]);

test('publishRaw() accepts exactly 128 KiB, the same limit as publish()', function () {
    $got = phasync::run(function () {
        $subscription = Swerve::subscribe('t');
        Swerve::publishRaw('t', \str_repeat('x', Topics::MAX_MESSAGE));
        foreach ($subscription as $message) {
            return \strlen($message);
        }
    });

    expect($got)->toBe(Topics::MAX_MESSAGE);
});

test('in a cluster, bytes published raw in one worker reach a subscriber in another worker unchanged, invalid UTF-8 included', function () {
    [$process, $addr] = swerve_start([], 4);
    try {
        $bytes = \implode('', \array_map('chr', \range(0, 255)));
        $conn  = native_connect($addr);
        \fwrite($conn, "GET /subscribe?topic=raw&n=1&hex=1 HTTP/1.1\r\nHost: t\r\n\r\n");
        native_read_head($conn);
        native_read_chunk($conn); // "ready <pid>"

        expect(probe($addr, '/publish-raw?topic=raw&m=' . \bin2hex($bytes)))->toBe('published');

        \preg_match('/^data: ([0-9a-f]*)\n\n$/', (string) native_read_chunk($conn), $m);
        expect(\hex2bin($m[1]))->toBe($bytes);
    } finally {
        native_stop($process);
    }
});

test('in a cluster, publish() and publishRaw() on one topic reach a subscriber in another worker, each in its own shape', function () {
    [$process, $addr] = swerve_start([], 4);
    try {
        $conn = native_connect($addr);
        \fwrite($conn, "GET /subscribe?topic=mixed&n=2&hex=1 HTTP/1.1\r\nHost: t\r\n\r\n");
        native_read_head($conn);
        native_read_chunk($conn); // "ready <pid>"

        expect(probe($addr, '/publish?topic=mixed&m=json-str'))->toBe('published');
        expect(probe($addr, '/publish-raw?topic=mixed&m=' . \bin2hex("\xFF\xFE")))->toBe('published');

        \preg_match('/^data: ([0-9a-f]*)\n\n$/', (string) native_read_chunk($conn), $m1);
        \preg_match('/^data: ([0-9a-f]*)\n\n$/', (string) native_read_chunk($conn), $m2);
        expect(\hex2bin($m1[1]))->toBe('json-str'); // publish(): a string stays a string
        expect(\hex2bin($m2[1]))->toBe("\xFF\xFE");   // publishRaw(): the exact bytes
    } finally {
        native_stop($process);
    }
});
