<?php

/*
 * swerve's PSR-7 messages: with*() returns a new message that shares the body stream. A stream is
 * not a value: a copy would be read while the original is written (see phasync/swerve#3).
 */

use phasync\Psr\UnbufferedStream;
use phasync\Psr\Request;
use phasync\Psr\Response;
use phasync\Psr\ServerRequest;
use phasync\Psr\StreamFactory;

test('with*() on a response keeps its body stream, also a streamed one', function () {
    phasync::run(function () {
        $body     = new UnbufferedStream();
        $response = new Response(200, ['Content-Type' => 'text/event-stream'], $body);
        expect($response->withAddedHeader('Set-Cookie', 'a=b')->getBody())->toBe($body);
        expect($response->withStatus(201)->withHeader('X-A', '1')->getBody())->toBe($body);
    });
    $stream = StreamFactory::create('hello');
    expect((new Response(200, [], $stream))->withStatus(404)->getBody())->toBe($stream);
});

test('with*() on a request keeps its body stream', function () {
    $stream = StreamFactory::create('hello');
    expect((new Request('POST', 'http://example.com/', $stream))->withHeader('X-A', '1')->getBody())->toBe($stream);
    $server = new ServerRequest('POST', 'http://example.com/', $stream);
    expect($server->withAttribute('a', 1)->withHeader('X-A', '1')->getBody())->toBe($stream);
});
