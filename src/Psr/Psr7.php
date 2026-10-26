<?php

namespace Swerve\Psr;

use phasync\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\ClientRequest;

/**
 * Serves an exchange with a closure taking a PSR-7 ServerRequestInterface and returning a
 * ResponseInterface. The request body is streamed from the client as the application reads it.
 *
 * @internal
 */
final class Psr7
{
    /** Bytes per read of a response body. */
    private const CHUNK = 65536;

    /** @param \Closure(ServerRequestInterface): ResponseInterface $app */
    public static function serve(ClientRequest $client, \Closure $app): void
    {
        $response = $app(self::request($client));
        if (!$response instanceof ResponseInterface) {
            throw new \TypeError('A PSR-7 handler must return a ' . ResponseInterface::class . ', got ' . \get_debug_type($response));
        }
        self::send($client, $response);
    }

    private static function request(ClientRequest $client): ServerRequestInterface
    {
        $method  = $client->getMethod();
        $target  = $client->getTarget();
        $headers = $client->getRequestHeaders();
        $now     = \microtime(true);
        $server  = [
            'REQUEST_METHOD'     => $method,
            'REQUEST_URI'        => $target,
            'QUERY_STRING'       => \parse_url($target, \PHP_URL_QUERY) ?: '',
            'SERVER_PROTOCOL'    => 'HTTP/' . $client->getProtocolVersion(),
            'REQUEST_TIME'       => (int) $now,
            'REQUEST_TIME_FLOAT' => $now,
        ];
        if ('https' === $client->getScheme()) {
            $server['HTTPS'] = 'on';
        }
        if (\preg_match('/^\[?(.+?)\]?:(\d+)$/', $client->peer(), $peer)) {
            $server['REMOTE_ADDR'] = $peer[1];
            $server['REMOTE_PORT'] = (int) $peer[2];
        }
        if (\preg_match('/^\[?(.+?)\]?:(\d+)$/', $client->local(), $local)) {
            $server['SERVER_ADDR'] = $local[1];
            $server['SERVER_PORT'] = (int) $local[2];
        }
        // A request has a body when it says so; its length is known unless it is chunked
        $size    = isset($headers['transfer-encoding']) ? null : (int) ($headers['content-length'][0] ?? 0);
        $body    = new RequestBody($client, $size);
        $form    = isset($headers['content-type']) ? FormBody::for($method, $headers['content-type'][0], $body) : null;
        $cookies = isset($headers['cookie']) ? ServerRequest::cookies(\implode('; ', $headers['cookie'])) : [];

        return new ServerRequest(
            $method,
            $target,
            $form ? $form->input(...) : $body,
            $headers,
            null,
            $server,
            $cookies,
            $form ? $form->files(...) : [],
            $form ? $form->fields(...) : null,
            [],
            $client->getProtocolVersion(),
        );
    }

    private static function send(ClientRequest $client, ResponseInterface $response): void
    {
        $body    = $response->getBody();
        $headers = $response->getHeaders();
        $size    = $body->getSize();
        if (null !== $size && $size > 0 && !$response->hasHeader('Content-Length')) {
            $headers['Content-Length'] = (string) $size;
        }
        $client->sendResponseHeaders($response->getStatusCode(), $headers);
        if ('HEAD' !== $client->getMethod()) {
            if ($body->isSeekable()) {
                $body->rewind(); // a response is built by writing to its body, which leaves it at the end
            }
            while (!$body->eof()) {
                $chunk = $body->read(self::CHUNK);
                if ('' !== $chunk) {
                    $client->write($chunk);
                }
            }
        }
        $client->end();
    }
}
