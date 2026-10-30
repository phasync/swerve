<?php
use Swerve\WebSocket;

function ws_admin(WebSocket $ws): void
{
    foreach ($ws as $message) {
        $ws->send("admin $message from " . getmypid());
    }
}
