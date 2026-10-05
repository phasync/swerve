<?php

/*
 * An application for the tests of tests/Wire: routes that let a raw client see what swerve puts on
 * and takes off the wire, whatever the application is written in.
 */

use phasync\Psr\Response;
use phasync\Psr\UnbufferedStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

return new class implements RequestHandlerInterface {
    /** Producers of /chunked still running, see /live. */
    private int $live = 0;

    /** How /slurp ended, see /slurped. */
    private string $slurped = 'never';

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        \parse_str($request->getUri()->getQuery(), $query);

        return match ($request->getUri()->getPath()) {
            '/hello'   => new Response(200, ['Content-Type' => 'text/plain'], 'Hello'),
            '/echo'    => new Response(200, [], (string) $request->getBody()),
            '/big'     => new Response(200, [], \str_repeat('x', (int) $query['n'])),
            '/status'  => new Response((int) $query['code'], [], ''),
            // The request as the application sees it, header names as the client wrote them
            '/request' => new Response(200, [], \json_encode([
                'method'  => $request->getMethod(),
                'target'  => $request->getRequestTarget(),
                'headers' => $request->getHeaders(),
            ], JSON_INVALID_UTF8_SUBSTITUTE)),
            // Response headers: several of one name, a mixed-case name
            '/headers' => new Response(200, ['Set-Cookie' => ['a=1', 'b=2'], 'X-Mixed-Case' => 'v', 'Vary' => ['Accept', 'Accept-Encoding']], 'x'),
            '/date'    => new Response(200, ['Date' => 'Mon, 01 Jan 2001 00:00:00 GMT'], 'x'),
            '/close'   => new Response(200, ['Connection' => 'close'], 'x'),
            // Framing headers are swerve's to decide
            '/framing' => new Response(200, ['Transfer-Encoding' => 'chunked', 'Keep-Alive' => 'timeout=5'], 'abc'),
            // A body of unknown size: ?n= pieces of ?size= bytes, appended as fast as the client reads, or ?ms= apart
            '/chunked' => (function () use ($query) {
                $out = new UnbufferedStream(65536, PHP_FLOAT_MAX);
                phasync::go(function () use ($out, $query) {
                    ++$this->live;
                    try {
                        for ($i = 0; $i < (int) $query['n']; ++$i) {
                            $out->append(\str_repeat(\chr(65 + $i % 26), (int) $query['size']));
                            if (isset($query['ms'])) {
                                phasync::sleep((int) $query['ms'] / 1000);
                            }
                        }
                        $out->end();
                    } finally {
                        --$this->live;
                    }
                });

                return new Response(200, [], $out);
            })(),
            '/live'    => new Response(200, [], (string) $this->live),
            // Reads the whole request body, and records whether that worked
            '/slurp'   => (function () use ($request) {
                try {
                    $body = $request->getBody();
                    $read = 0;
                    while (!$body->eof()) {
                        $read += \strlen($body->read(8192));
                    }
                    $this->slurped = "read $read";
                } catch (RuntimeException) {
                    $this->slurped = 'failed';
                }

                return new Response(200, [], $this->slurped);
            })(),
            '/slurped' => new Response(200, [], $this->slurped),
            default    => new Response(404, [], 'Not found'),
        };
    }
};
