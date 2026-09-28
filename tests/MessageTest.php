<?php

/*
 * swerve's PSR-7 messages: with*() returns a new message that shares the body stream. A stream is
 * not a value: a copy would be read while the original is written (see phasync/swerve#3).
 */

use phasync\Psr\UnbufferedStream;
use Swerve\Http\Message\Request;
use Swerve\Http\Message\Response;
use Swerve\Http\Message\ServerRequest;
use Swerve\Http\Message\Stream;

test('with*() on a response keeps its body stream, also a streamed one', function () {
    phasync::run(function () {
        $body     = new UnbufferedStream();
        $response = new Response($body, ['Content-Type' => 'text/event-stream']);
        expect($response->withAddedHeader('Set-Cookie', 'a=b')->getBody())->toBe($body);
        expect($response->withStatus(201)->withHeader('X-A', '1')->getBody())->toBe($body);
    });
    $stream = Stream::create('hello');
    expect((new Response($stream))->withStatus(404)->getBody())->toBe($stream);
});

test('with*() on a request keeps its body stream', function () {
    $stream = Stream::create('hello');
    expect((new Request('POST', 'http://example.com/', $stream))->withHeader('X-A', '1')->getBody())->toBe($stream);
    $server = new ServerRequest('POST', 'http://example.com/', $stream);
    expect($server->withAttribute('a', 1)->withHeader('X-A', '1')->getBody())->toBe($stream);
});
