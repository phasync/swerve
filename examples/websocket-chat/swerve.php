<?php

/*
 * A chat room over WebSockets: each browser connects to /chat, sends messages as JSON, and
 * receives everyone's. Messages go through Swerve::publish(), so connections in different
 * workers share the room.
 *
 *     vendor/bin/swerve --public=examples/websocket-chat/public examples/websocket-chat/swerve.php
 */

use phasync\CancelledException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Http\Message\Response;
use Swerve\Http\WebSocket;
use Swerve\SubscriberLagException;
use Swerve\Swerve;

return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ('/chat' !== $request->getUri()->getPath()) {
            return new Response('Not found', [], 404);
        }

        return WebSocket::from($request, static function (WebSocket $ws) {
            // Everything published to the room goes to this browser, from a coroutine of its own
            $subscription = Swerve::subscribe('chat');
            $forward      = phasync::go(static function () use ($ws, $subscription) {
                try {
                    foreach ($subscription as $message) {
                        $ws->send($message);
                    }
                } catch (SubscriberLagException) {
                    $ws->close(1008); // too far behind; the browser reconnects
                } catch (CancelledException) {
                    // The browser left, see below
                }
            });

            // What this browser sends goes to the room
            foreach ($ws as $message) {
                $data = \json_decode($message, true);
                $name = \trim((string) ($data['name'] ?? ''));
                $text = \trim((string) ($data['text'] ?? ''));
                if ('' !== $name && '' !== $text && \mb_strlen($name) <= 40 && \mb_strlen($text) <= 2000) {
                    Swerve::publish('chat', \json_encode(['name' => $name, 'text' => $text, 'at' => \time()]));
                }
            }

            // The browser left: stop forwarding, which ends the subscription
            if (!$forward->isTerminated()) {
                phasync::cancel($forward);
            }
        });
    }
};
