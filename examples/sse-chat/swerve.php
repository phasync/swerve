<?php

/*
 * A chat room over Server-Sent Events: the browser receives messages from GET /events and
 * sends them with POST /messages. Every worker's subscribers get every message, whichever
 * worker received the POST.
 *
 *     vendor/bin/swerve --public=examples/sse-chat/public examples/sse-chat/swerve.php
 */

use Nyholm\Psr7\Response;
use phasync\Psr\UnbufferedStream;
use phasync\TimeoutException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\SubscriberLagException;
use Swerve\Swerve;

return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return match ([$request->getMethod(), $request->getUri()->getPath()]) {
            ['GET', '/events']    => $this->events(),
            ['POST', '/messages'] => $this->post($request),
            default               => new Response(404, [], 'Not found'),
        };
    }

    /**
     * The stream of messages. Subscribed before the response is returned, so nothing published
     * from here on is missed. The response body is written by a coroutine of its own, while
     * swerve sends what it writes.
     */
    private function events(): ResponseInterface
    {
        // A null every 15 s without a message, for a keep-alive; the loop ends when the worker
        // drains (a shutdown or reload), and the browser reconnects to another
        $subscription = Swerve::subscribe('chat', heartbeat: 15);
        // A 1-byte buffer: append() waits until swerve took the event, and throws
        // TimeoutException after 60 s of that, which is how the producer learns that the
        // client left. The keep-alive makes sure it tries.
        $out = new UnbufferedStream(1, 60);
        phasync::go(static function () use ($subscription, $out) {
            try {
                $out->append("retry: 1000\n\n");
                foreach ($subscription as $message) {
                    $out->append(null === $message ? ": keep-alive\n\n" : "data: $message\n\n");
                }
            } catch (TimeoutException) {
                // The client left
            } catch (SubscriberLagException) {
                // Too far behind: the browser's EventSource reconnects
            } finally {
                $out->end();
            }
        });

        return new Response(200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache'], $out);
    }

    private function post(ServerRequestInterface $request): ResponseInterface
    {
        $data = \json_decode((string) $request->getBody(), true);
        $name = \trim((string) ($data['name'] ?? ''));
        $text = \trim((string) ($data['text'] ?? ''));
        if ('' === $name || '' === $text || \mb_strlen($name) > 40 || \mb_strlen($text) > 2000) {
            return new Response(422, ['Content-Type' => 'text/plain'], 'name (1-40) and text (1-2000) required');
        }
        Swerve::publish('chat', \json_encode(['name' => $name, 'text' => $text, 'at' => \time()]));

        return new Response(204);
    }
};
