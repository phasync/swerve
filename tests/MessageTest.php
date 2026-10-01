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

test('a Swerve request reads its cookies from the Cookie headers, when first asked', function () {
    $request = new Swerve\Http\ServerRequest('GET', '/', StreamFactory::create(''), ['cookie' => ['a=1; b=x%20y', 'c=3']], ['cookie' => 'COOKIE'], [], '1.1');
    expect($request->getCookieParams())->toBe(['a' => '1', 'b' => 'x y', 'c' => '3']);
    expect((new Swerve\Http\ServerRequest('GET', '/', StreamFactory::create(''), [], [], [], '1.1'))->getCookieParams())->toBe([]);
    expect($request->withCookieParams(['z' => '9'])->getCookieParams())->toBe(['z' => '9']);
    expect($request->withCookieParams([])->withAttribute('a', 1)->getCookieParams())->toBe([]);
});

test('a Swerve request parses a POST form from its Content-Type, except for an upgrade', function () {
    $make = fn (array $headers, bool $upgrade = false, string $method = 'POST') => new Swerve\Http\ServerRequest($method, '/', StreamFactory::create('a=1&b[]=2'), $headers, [], [], '1.1', $upgrade);
    expect($make(['content-type' => ['application/x-www-form-urlencoded']])->getParsedBody())->toBe(['a' => '1', 'b' => ['2']]);
    expect($make(['content-type' => ['application/x-www-form-urlencoded']], true)->getParsedBody())->toBeNull();
    expect($make(['content-type' => ['application/x-www-form-urlencoded']], false, 'PUT')->getParsedBody())->toBeNull();
    expect($make(['content-type' => ['application/json']])->getParsedBody())->toBeNull();
});

test('a Swerve request carries the attributes its server gives it', function () {
    $body = StreamFactory::create('a=1');
    $form = new Swerve\Http\ServerRequest('POST', '/', $body, ['content-type' => ['application/x-www-form-urlencoded']], ['content-type' => 'Content-Type'], [], '1.1', false, ['server' => 1]);
    $plain = new Swerve\Http\ServerRequest('GET', '/', $body, [], [], [], '1.1', false, ['server' => 2]);
    expect($form->getAttribute('server'))->toBe(1)->and($form->getParsedBody())->toBe(['a' => '1']);
    expect($plain->getAttributes())->toBe(['server' => 2]);
});
