<?php
use Swerve\WebSocket;

// The WebSocket workers for this file load it once, as they start, in the global scope
$boots = ($boots ?? 0) + 1;

function ws_echo(WebSocket $ws): void
{
    foreach ($ws as $message) {
        $ws->send("echo $message from " . getmypid());
    }
}

function ws_state(WebSocket $ws): void
{
    foreach ($ws as $message) {
        $ws->send(json_encode([$_GET, $_SERVER['QUERY_STRING'], $_SESSION, date_default_timezone_get()]));
    }
}

function ws_boots(WebSocket $ws): void
{
    foreach ($ws as $message) {
        $ws->send(json_encode([$GLOBALS['boots'], getmypid()]));
    }
}

final class WsHandlers
{
    public static function busy(WebSocket $ws): void
    {
        foreach ($ws as $message) {
            if ('block' === $message) {
                // About two seconds in one C call: no coroutine of this process runs meanwhile
                password_hash('x', PASSWORD_BCRYPT, ['cost' => 15]);
            }
            $ws->send("$message from " . getmypid());
        }
    }
}
