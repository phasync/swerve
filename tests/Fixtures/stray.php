<?php

/*
 * An application that breaks the rule of docs/stray-output.md: it echoes outside the response
 * it returns. Also what it takes to show that streams swerve serves itself are not affected.
 */

use phasync\Psr\Response;
use phasync\Psr\UnbufferedStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

if (\getenv('SWERVE_TEST_ECHO_ON_LOAD')) {
    echo "echoed while loading\n"; // STRAY-LOAD
}

return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        \parse_str($request->getUri()->getQuery(), $query);
        $ms = (int) ($query['ms'] ?? 0) / 1000;

        switch ($request->getUri()->getPath()) {
            case '/hello':
                return new Response(200, [], 'Hello');
            case '/pid':
                return new Response(200, [], (string) \getmypid());
            case '/level': // output buffers in place when the request runs
                return new Response(200, [], (string) \ob_get_level());
            case '/echo':
                echo 'stray ' . ($query['m'] ?? 'x'); // STRAY-ECHO
                return new Response(200, [], 'returned');
            case '/sleep':
                \phasync::sleep($ms);

                return new Response(200, [], 'slept');
            case '/echo-after': // another request's time to be in flight
                \phasync::sleep($ms);
                echo 'late stray'; // STRAY-LATE
                return new Response(200, [], 'returned');
            case '/echo-child': // from a coroutine the request started
                \phasync::await(\phasync::go(static function () {
                    echo 'child stray'; // STRAY-CHILD
                }));

                return new Response(200, [], 'returned');
            case '/strip': // what code that cleans up after itself does: the guard stays
                @\ob_end_clean();
                @\ob_end_flush();
                echo 'after strip'; // STRAY-STRIP
                return new Response(200, [], 'returned');
            case '/captured': // a handler of its own on top of the guard takes the output: nothing reaches the guard
                $seen = '';
                \ob_start(static function (string $chunk) use (&$seen): string {
                    $seen .= $chunk;

                    return '';
                }, 1);
                echo 'captured';
                \ob_end_clean();

                return new Response(200, [], $seen . ' ' . \ob_get_level());
            case '/sse': // ?n= events, ?ms= apart, through swerve's own stream class
                $out = new UnbufferedStream(65536, PHP_FLOAT_MAX);
                \phasync::go(static function () use ($out, $query, $ms) {
                    for ($i = 0; $i < (int) $query['n']; ++$i) {
                        $i > 0 && \phasync::sleep($ms);
                        $out->append("data: $i\n\n");
                    }
                    $out->end();
                });

                return new Response(200, ['Content-Type' => 'text/event-stream'], $out);
        }

        return new Response(404, [], 'Not found');
    }
};
