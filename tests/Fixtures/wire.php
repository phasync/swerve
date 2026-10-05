<?php

/*
 * An application for the tests of tests/Wire: routes that let a raw client see what swerve puts on
 * and takes off the wire, whatever the application is written in.
 */

use Swerve\ClientRequest;
use Swerve\RequestHandler;

function wire_send(ClientRequest $r, int $status, array $headers, string $body): void
{
    $r->sendResponseHeaders($status, $headers + ['Content-Length' => (string) \strlen($body)]);
    $r->write($body);
}

return new RequestHandler((new class {
    /** Producers of /chunked still running, see /live. */
    private int $live = 0;

    /** How /slurp ended, see /slurped. */
    private string $slurped = 'never';

    public function __invoke(ClientRequest $r): void
    {
        $target = $r->getTarget();
        $path   = \parse_url($target, \PHP_URL_PATH);
        \parse_str((string) \parse_url($target, \PHP_URL_QUERY), $query);

        switch ($path) {
            case '/hello':
                wire_send($r, 200, ['Content-Type' => 'text/plain'], 'Hello');

                return;
            case '/echo':
                $r->sendResponseHeaders(200);
                while ('' !== ($piece = $r->read())) {
                    $r->write($piece);
                }

                return;
            case '/big':
                $r->sendResponseHeaders(200, ['Content-Length' => $query['n']]);
                $r->write(\str_repeat('x', (int) $query['n']));

                return;
            case '/status':
                $r->sendResponseHeaders((int) $query['code'], ['Content-Length' => '0']);

                return;
            // The request as the application sees it, header names in lower case
            case '/request':
                wire_send($r, 200, [], \json_encode([
                    'method'  => $r->getMethod(),
                    'target'  => $target,
                    'headers' => $r->getRequestHeaders(),
                ], \JSON_INVALID_UTF8_SUBSTITUTE));

                return;
            // Response headers: several of one name, a mixed-case name
            case '/headers':
                wire_send($r, 200, ['Set-Cookie' => ['a=1', 'b=2'], 'X-Mixed-Case' => 'v', 'Vary' => ['Accept', 'Accept-Encoding']], 'x');

                return;
            case '/date':
                wire_send($r, 200, ['Date' => 'Mon, 01 Jan 2001 00:00:00 GMT'], 'x');

                return;
            case '/close':
                wire_send($r, 200, ['Connection' => 'close'], 'x');

                return;
            // Framing headers are swerve's to decide
            case '/framing':
                wire_send($r, 200, ['Keep-Alive' => 'timeout=5'], 'abc');

                return;
            // A body of unknown size: ?n= pieces of ?size= bytes, written as fast as the client reads, or ?ms= apart
            case '/chunked':
                ++$this->live;
                try {
                    $r->sendResponseHeaders(200);
                    for ($i = 0; $i < (int) $query['n']; ++$i) {
                        $r->write(\str_repeat(\chr(65 + $i % 26), (int) $query['size']));
                        if (isset($query['ms'])) {
                            phasync::sleep((int) $query['ms'] / 1000);
                        }
                    }
                } finally {
                    --$this->live;
                }

                return;
            case '/live':
                wire_send($r, 200, [], (string) $this->live);

                return;
            // Reads the whole request body, and records whether that worked
            case '/slurp':
                try {
                    $read = 0;
                    while ('' !== ($piece = $r->read(8192))) {
                        $read += \strlen($piece);
                    }
                    $this->slurped = "read $read";
                } catch (\phasync\IOException) {
                    $this->slurped = 'failed';
                }
                wire_send($r, 200, [], $this->slurped);

                return;
            case '/slurped':
                wire_send($r, 200, [], $this->slurped);

                return;
            default:
                wire_send($r, 404, [], 'Not found');
        }
    }
})->__invoke(...));
