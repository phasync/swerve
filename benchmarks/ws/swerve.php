<?php

/*
 * WebSocket fan-out benchmark: every connection to /news gets what is published to 'news'.
 *
 *     vendor/bin/swerve --http=:18400 --http=:18401 --http=:18402 --http=:18403 benchmarks/ws/swerve.php
 */

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Http\Message\Response;
use Swerve\Http\WebSocket;
use Swerve\Swerve;

return new class implements RequestHandlerInterface {
    private int $sockets = 0;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return match ($request->getUri()->getPath()) {
            '/news' => WebSocket::from($request, function (WebSocket $ws) {
                ++$this->sockets;
                try {
                    foreach (Swerve::subscribe('news', maxLag: 60) as $message) {
                        $ws->send($message);
                    }
                } finally {
                    --$this->sockets;
                }
            }),
            // The body is published as it is
            '/publish' => (function () use ($request) {
                Swerve::publish('news', (string) $request->getBody());

                return new Response('ok');
            })(),
            '/stats' => new Response(\json_encode(['pid' => \getmypid(), 'sockets' => $this->sockets, 'memory' => \memory_get_usage(true)])),
            default => new Response('Not found', [], 404),
        };
    }
};
