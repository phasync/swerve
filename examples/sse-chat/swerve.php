<?php

/*
 * A chat room over Server-Sent Events: the browser receives messages from GET /events and
 * sends them with POST /messages. Every worker's subscribers get every message, whichever
 * worker received the POST.
 *
 *     vendor/bin/swerve examples/sse-chat/swerve.php
 */

use phasync\IOException;
use Swerve\ServerSentEvents;
use Swerve\SubscriberLagException;
use Swerve\Swerve;

while (Swerve::accept(__DIR__ . '/public', static function (): void {
    switch ($_SERVER['REQUEST_METHOD'] . ' ' . \strtok($_SERVER['REQUEST_URI'], '?')) {
        case 'GET /events':
            Swerve::upgrade(static function (ServerSentEvents $sse): void {
                // A null every 15 s without a message, for a keep-alive. When the worker stops (a
                // shutdown or reload), the loop throws a WorkerStoppingException, swerve cuts the
                // stream, and the browser reconnects to another
                try {
                    foreach (Swerve::subscribe('chat', heartbeat: 15) as $message) {
                        // The keep-alive makes sure a client that left is noticed: this write fails
                        null === $message ? $sse->comment('keep-alive') : $sse->send($message);
                    }
                } catch (IOException) {
                    // The client left
                } catch (SubscriberLagException) {
                    // Too far behind: the browser's EventSource reconnects
                }
            });

            return;
        case 'POST /messages':
            $data = \json_decode(\file_get_contents('php://input'), true);
            $name = \trim((string) ($data['name'] ?? ''));
            $text = \trim((string) ($data['text'] ?? ''));
            if ('' === $name || '' === $text || \mb_strlen($name) > 40 || \mb_strlen($text) > 2000) {
                \http_response_code(422);
                \header('Content-Type: text/plain');
                echo 'name (1-40) and text (1-2000) required';

                return;
            }
            Swerve::publish('chat', \json_encode(['name' => $name, 'text' => $text, 'at' => \time()]));
            \http_response_code(204);

            return;
        default:
            \http_response_code(404);
            \header('Content-Type: text/plain');
            echo 'Not found';
    }
})) {
}
