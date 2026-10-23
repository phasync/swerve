<?php

/*
 * An application for the tests of tests/Wire: routes that let a raw client see what swerve puts on
 * and takes off the wire, whatever the application is written in.
 */

use phasync\Psr\Response;
use phasync\Psr\StringStream;
use phasync\Psr\UnbufferedStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Swerve;

function wire_send(int $status, array $headers, string $body): ResponseInterface
{
    return new Response($status, $headers, new StringStream($body));
}

while (true) {
    Swerve::accept(null, (new class {
        /** Producers of /chunked still running, see /live. */
        private int $live = 0;

        /** How /slurp ended, see /slurped. */
        private string $slurped = 'never';

        public function __invoke(ServerRequestInterface $r): ResponseInterface
        {
            $path  = $r->getUri()->getPath();
            $query = $r->getQueryParams();

            switch ($path) {
                case '/hello':
                    return wire_send(200, ['Content-Type' => 'text/plain'], 'Hello');
                case '/echo':
                    $body = $r->getBody();

                    return $this->streamed(200, [], static function (UnbufferedStream $stream) use ($body) {
                        while ('' !== ($piece = $body->read(65536))) {
                            $stream->append($piece);
                        }
                    });
                case '/big':
                    return wire_send(200, ['Content-Length' => $query['n']], \str_repeat('x', (int) $query['n']));
                case '/status':
                    return new Response((int) $query['code'], ['Content-Length' => '0']);
                // The request as the application sees it, header names in lower case
                case '/request':
                    return wire_send(200, [], \json_encode([
                        'method'  => $r->getMethod(),
                        'target'  => $r->getRequestTarget(),
                        'headers' => $r->getHeaders(),
                    ], \JSON_INVALID_UTF8_SUBSTITUTE));
                // Response headers: several of one name, a mixed-case name
                case '/headers':
                    return wire_send(200, ['Set-Cookie' => ['a=1', 'b=2'], 'X-Mixed-Case' => 'v', 'Vary' => ['Accept', 'Accept-Encoding']], 'x');
                case '/date':
                    return wire_send(200, ['Date' => 'Mon, 01 Jan 2001 00:00:00 GMT'], 'x');
                case '/close':
                    return wire_send(200, ['Connection' => 'close'], 'x');
                // Framing headers are swerve's to decide
                case '/framing':
                    return wire_send(200, ['Keep-Alive' => 'timeout=5'], 'abc');
                // A body of unknown size: ?n= pieces of ?size= bytes, written as fast as the client reads, or ?ms= apart
                case '/chunked':
                    return $this->streamed(200, [], function (UnbufferedStream $stream) use ($query) {
                        ++$this->live;
                        try {
                            for ($i = 0; $i < (int) $query['n']; ++$i) {
                                $stream->append(\str_repeat(\chr(65 + $i % 26), (int) $query['size']));
                                if (isset($query['ms'])) {
                                    phasync::sleep((int) $query['ms'] / 1000);
                                }
                            }
                        } finally {
                            --$this->live;
                        }
                    });
                case '/live':
                    return wire_send(200, [], (string) $this->live);
                // Reads the whole request body, and records whether that worked
                case '/slurp':
                    try {
                        $body = $r->getBody();
                        $read = 0;
                        while ('' !== ($piece = $body->read(8192))) {
                            $read += \strlen($piece);
                        }
                        $this->slurped = "read $read";
                    } catch (\RuntimeException) {
                        $this->slurped = 'failed';
                    }

                    return wire_send(200, [], $this->slurped);
                case '/slurped':
                    return wire_send(200, [], $this->slurped);
                default:
                    return wire_send(404, [], 'Not found');
            }
        }

        /** @param \Closure(UnbufferedStream): void $write */
        private function streamed(int $status, array $headers, \Closure $write): ResponseInterface
        {
            $stream = new UnbufferedStream();
            phasync::go(static function () use ($stream, $write) {
                try {
                    $write($stream);
                } finally {
                    $stream->end();
                }
            });

            return new Response($status, $headers, $stream);
        }
    })->__invoke(...));
}
