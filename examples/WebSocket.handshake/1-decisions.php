<?php

use Swerve\WebSocket;

$websocket = [
    'host'                  => ['example.com'],
    'upgrade'               => ['websocket'],
    'connection'            => ['Upgrade'],
    'sec-websocket-key'     => ['dGhlIHNhbXBsZSBub25jZQ=='],
    'sec-websocket-version' => ['13'],
    'sec-websocket-protocol' => ['chat.v2, chat.v1'],
];
$requests = [
    'a WebSocket handshake' => $websocket,
    'a plain GET'           => ['host' => ['example.com']],
    'a wrong version'       => ['sec-websocket-version' => ['8']] + $websocket,
    'a foreign origin'      => ['origin' => ['https://evil.example']] + $websocket,
];
foreach ($requests as $what => $headers) {
    $decision = WebSocket::handshake('GET', '1.1', $headers, false, subprotocols: ['chat.v1'], origins: ['https://example.com']);
    echo str_pad($what, 24), $decision->status, ' ', $decision->subprotocol ?? $decision->body, "\n";
}
