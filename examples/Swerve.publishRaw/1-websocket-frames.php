<?php

use Swerve\ClientRequest;
use Swerve\Swerve;
use Swerve\WebSocket;

// Binary WebSocket frames, forwarded to every worker's subscribers: publish() would fail to
// encode them as JSON, since a binary frame is not valid UTF-8.
$app = static function () {
    Swerve::upgrade(static function (ClientRequest $request) {
        WebSocket::from($request, function (WebSocket $ws) {
            $ws->onMessage->listen(function (string $data, bool $binary) {
                if ($binary) {
                    Swerve::publishRaw('frames', $data);      // to this and every other worker
                }
            });
            foreach (Swerve::subscribe('frames') as $frame) { // what this and every other connection sent
                $ws->sendBinary($frame);
            }
        });
    });
};

if (debug_backtrace()) {   // swerve runs this file: it is the application's swerve.php
    while (Swerve::accept(null, $app)) {
    }

    return;
}

// Run directly: serve the application, send one binary frame, and print what comes back
require __DIR__ . '/../serve.php';
[$swerve, $addr] = swerve_start(__FILE__);
try {
    $connection = stream_socket_client("tcp://$addr", $errno, $error, 5);
    fwrite($connection, "GET /frames HTTP/1.1\r\nHost: example\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
        . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $head .= fread($connection, 1);
    }
    $mask    = "\1\2\3\4";
    $payload = pack('nN', 1, 42);                         // any bytes, not valid UTF-8
    fwrite($connection, "\x82" . chr(0x80 | strlen($payload)) . $mask . ($payload ^ str_repeat($mask, 32)));
    $head   = fread($connection, 2);
    $length = ord($head[1]) & 0x7F;
    echo 'sent ', bin2hex($payload), ', got back ', bin2hex((string) fread($connection, $length)), "\n";
    fclose($connection);
} finally {
    swerve_stop($swerve, false);
}
