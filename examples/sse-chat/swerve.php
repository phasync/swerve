<?php

/*
 * A chat room over Server-Sent Events: the browser receives messages from GET /events and
 * sends them with POST /messages. Every worker's subscribers get every message, whichever
 * worker received the POST.
 *
 *     vendor/bin/swerve --public=examples/sse-chat/public examples/sse-chat/swerve.php
 */

use phasync\IOException;
use Swerve\ClientRequest;
use Swerve\RequestHandler;
use Swerve\SubscriberLagException;
use Swerve\Swerve;

return new RequestHandler(static function (ClientRequest $request) {
    switch ([$request->getMethod(), \strtok($request->getTarget(), '?')]) {
        case ['GET', '/events']:
            // A null every 15 s without a message, for a keep-alive; the loop ends when the worker
            // drains (a shutdown or reload), and the browser reconnects to another
            $subscription = Swerve::subscribe('chat', heartbeat: 15);
            $request->sendResponseHeaders(200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache']);
            try {
                $request->write("retry: 1000\n\n");
                foreach ($subscription as $message) {
                    // The keep-alive makes sure a client that left is noticed: this write fails
                    $request->write(null === $message ? ": keep-alive\n\n" : "data: $message\n\n");
                }
            } catch (IOException) {
                // The client left
            } catch (SubscriberLagException) {
                // Too far behind: the browser's EventSource reconnects
            }

            return;
        case ['POST', '/messages']:
            $body = '';
            while ('' !== ($piece = $request->read())) {
                $body .= $piece;
            }
            $data = \json_decode($body, true);
            $name = \trim((string) ($data['name'] ?? ''));
            $text = \trim((string) ($data['text'] ?? ''));
            if ('' === $name || '' === $text || \mb_strlen($name) > 40 || \mb_strlen($text) > 2000) {
                $request->sendResponseHeaders(422, ['Content-Type' => 'text/plain']);
                $request->write('name (1-40) and text (1-2000) required');

                return;
            }
            Swerve::publish('chat', \json_encode(['name' => $name, 'text' => $text, 'at' => \time()]));
            $request->sendResponseHeaders(204);

            return;
        default:
            $request->sendResponseHeaders(404, ['Content-Type' => 'text/plain']);
            $request->write('Not found');
    }
});
