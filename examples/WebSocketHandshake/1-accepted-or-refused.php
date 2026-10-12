<?php

use Swerve\WebSocket;

$headers = [
    'upgrade'               => ['websocket'],
    'connection'            => ['Upgrade'],
    'sec-websocket-key'     => ['dGhlIHNhbXBsZSBub25jZQ=='],
    'sec-websocket-version' => ['13'],
];

$accepted = WebSocket::handshake('GET', '1.1', $headers, false);
var_dump($accepted->accepted());
print_r($accepted->headers);            // the 101 response's headers

$refused = WebSocket::handshake('POST', '1.1', $headers, true);
var_dump($refused->accepted());
echo $refused->status, ' ', $refused->body, "\n";   // the complete final response
print_r($refused->headers);
