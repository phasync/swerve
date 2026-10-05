<?php

use Swerve\WebSocket;
use Swerve\WebSocketHandshake;

/*
 * The handshake decision, without sockets: the function the adapters call with the parts of their
 * own request. The wire tests (WebSocketTest.php) pin the same answers from the outside.
 */

const HS_KEY    = 'dGhlIHNhbXBsZSBub25jZQ==';     // RFC 6455's example: accept s3pPLMBiTxaQ9kYGzzhZRbK+xOo=
const HS_ACCEPT = 's3pPLMBiTxaQ9kYGzzhZRbK+xOo=';

/** @param array<string, string|list<string>|null> $changes */
function hs_headers(array $changes = []): array
{
    $headers = [
        'host'                   => ['t'],
        'upgrade'                => ['websocket'],
        'connection'             => ['Upgrade'],
        'sec-websocket-key'      => [HS_KEY],
        'sec-websocket-version'  => ['13'],
    ];
    foreach ($changes as $name => $value) {
        if (null === $value) {
            unset($headers[$name]);
        } else {
            $headers[$name] = (array) $value;
        }
    }

    return $headers;
}

test('a valid handshake is accepted: the 101 headers carry the RFC accept value, and no subprotocol unless one is chosen', function () {
    $h = WebSocket::handshake('GET', '1.1', hs_headers(), false);

    expect($h)->toBeInstanceOf(WebSocketHandshake::class);
    expect($h->accepted())->toBeTrue();
    expect($h->status)->toBe(101);
    expect($h->headers)->toBe(['upgrade' => 'websocket', 'connection' => 'Upgrade', 'sec-websocket-accept' => HS_ACCEPT]);
    expect($h->subprotocol)->toBeNull();
    expect($h->body)->toBe('');
});

test('the decision matrix: what is 426, what 400, and what is accepted', function (array $changes, string $method, string $version, bool $body, int $status) {
    $h = WebSocket::handshake($method, $version, hs_headers($changes), $body);

    expect($h->status)->toBe($status);
})->with([
    'accepted'                             => [[], 'GET', '1.1', false, 101],
    'tokens in lists and any case'         => [['upgrade' => 'foo, WebSocket', 'connection' => ['keep-alive', 'UPGRADE']], 'GET', '1.1', false, 101],
    'no key'                               => [['sec-websocket-key' => null], 'GET', '1.1', false, 400],
    'an empty key'                         => [['sec-websocket-key' => ''], 'GET', '1.1', false, 400],
    'a key that is not base64'             => [['sec-websocket-key' => '!!!!!!!!!!!!!!!!!!!!!!=='], 'GET', '1.1', false, 400],
    'a key of 15 bytes'                    => [['sec-websocket-key' => \base64_encode(\str_repeat('a', 15))], 'GET', '1.1', false, 400],
    'a key of 17 bytes'                    => [['sec-websocket-key' => \base64_encode(\str_repeat('a', 17))], 'GET', '1.1', false, 400],
    'two keys'                             => [['sec-websocket-key' => [HS_KEY, HS_KEY]], 'GET', '1.1', false, 400],
    'version 8'                            => [['sec-websocket-version' => '8'], 'GET', '1.1', false, 400],
    'no version'                           => [['sec-websocket-version' => null], 'GET', '1.1', false, 400],
    'versions 13 and 8'                    => [['sec-websocket-version' => '13, 8'], 'GET', '1.1', false, 400],
    'POST'                                 => [[], 'POST', '1.1', false, 400],
    'HTTP/1.0'                             => [[], 'GET', '1.0', false, 400],
    'a body'                               => [[], 'GET', '1.1', true, 400],
    'no Upgrade'                           => [['upgrade' => null], 'GET', '1.1', false, 426],
    'another Upgrade'                      => [['upgrade' => 'h2c'], 'GET', '1.1', false, 426],
    'a token that only contains websocket' => [['upgrade' => 'websockets'], 'GET', '1.1', false, 426],
    'no Connection'                        => [['connection' => null], 'GET', '1.1', false, 426],
    'Connection without upgrade'           => [['connection' => 'keep-alive'], 'GET', '1.1', false, 426],
    'neither, and also POST'               => [['upgrade' => null, 'connection' => null], 'POST', '1.1', true, 426],
]);

test('a refusal is a complete answer: status, headers and body, exactly as core sends it', function () {
    $h = WebSocket::handshake('GET', '1.1', hs_headers(['upgrade' => null]), false);
    expect($h->accepted())->toBeFalse();
    expect($h->status)->toBe(426);
    expect($h->body)->toBe('This address speaks WebSocket');
    expect($h->headers)->toBe(['upgrade' => 'websocket', 'connection' => 'Upgrade', 'content-type' => 'text/plain', 'content-length' => '29']);
    expect($h->subprotocol)->toBeNull();

    $h = WebSocket::handshake('GET', '1.1', hs_headers(['sec-websocket-version' => '8']), false);
    expect($h->status)->toBe(400);
    expect($h->body)->toBe('Not a WebSocket handshake');
    expect($h->headers)->toBe(['sec-websocket-version' => '13', 'content-type' => 'text/plain', 'content-length' => '25']);

    $h = WebSocket::handshake('GET', '1.1', hs_headers(['origin' => 'https://evil.example']), false, origins: ['https://good.example']);
    expect($h->status)->toBe(403);
    expect($h->body)->toBe('This origin may not connect');
    expect($h->headers)->toBe(['content-type' => 'text/plain', 'content-length' => '27']);
});

test('origins: compared case-insensitively, off when null, and a request without an Origin is let through', function () {
    $allowed = ['https://Good.example'];

    expect(WebSocket::handshake('GET', '1.1', hs_headers(['origin' => 'HTTPS://good.EXAMPLE']), false, origins: $allowed)->status)->toBe(101);
    expect(WebSocket::handshake('GET', '1.1', hs_headers(['origin' => 'https://evil.example']), false, origins: $allowed)->status)->toBe(403);
    expect(WebSocket::handshake('GET', '1.1', hs_headers(), false, origins: $allowed)->status)->toBe(101);
    expect(WebSocket::handshake('GET', '1.1', hs_headers(['origin' => 'https://evil.example']), false)->status)->toBe(101);
    expect(WebSocket::handshake('GET', '1.1', hs_headers(['origin' => 'https://evil.example']), false, origins: [])->status)->toBe(403);
});

test('a refusal for the handshake wins over the origin, and 426 over 400', function () {
    expect(WebSocket::handshake('GET', '1.1', hs_headers(['origin' => 'https://evil.example', 'sec-websocket-version' => '8']), false, origins: ['https://good.example'])->status)->toBe(400);
    expect(WebSocket::handshake('POST', '1.1', hs_headers(['connection' => null]), false)->status)->toBe(426);
});

test('subprotocols: the first of the server\'s list that the client offered, sent back in the 101 headers', function () {
    $offer = hs_headers(['sec-websocket-protocol' => ['v1.chat, other', 'v2.chat']]);

    $h = WebSocket::handshake('GET', '1.1', $offer, false, ['v2.chat', 'v1.chat']);
    expect($h->subprotocol)->toBe('v2.chat');
    expect($h->headers)->toBe(['upgrade' => 'websocket', 'connection' => 'Upgrade', 'sec-websocket-accept' => HS_ACCEPT, 'sec-websocket-protocol' => 'v2.chat']);

    $h = WebSocket::handshake('GET', '1.1', $offer, false, ['nothing', 'in-common']);
    expect($h->accepted())->toBeTrue();
    expect($h->subprotocol)->toBeNull();
    expect($h->headers)->not->toHaveKey('sec-websocket-protocol');

    expect(WebSocket::handshake('GET', '1.1', $offer, false)->subprotocol)->toBeNull();
    expect(WebSocket::handshake('GET', '1.1', hs_headers(), false, ['v1.chat'])->subprotocol)->toBeNull();
    expect(WebSocket::handshake('GET', '1.1', hs_headers(['sec-websocket-protocol' => 'V1.CHAT']), false, ['v1.chat'])->subprotocol)->toBeNull(); // case-sensitive, as the protocol says
});

test('the extensions the client offers are ignored', function () {
    $h = WebSocket::handshake('GET', '1.1', hs_headers(['sec-websocket-extensions' => 'permessage-deflate']), false);

    expect($h->accepted())->toBeTrue();
    expect($h->headers)->not->toHaveKey('sec-websocket-extensions');
});
