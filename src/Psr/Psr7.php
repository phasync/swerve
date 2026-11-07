<?php

namespace Swerve\Psr;

use phasync;
use phasync\Psr\EmptyStream;
use phasync\Psr\ResourceStream;
use phasync\Psr\ServerRequest;
use phasync\Psr\UnbufferedStream;
use phasync\Psr\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\ClientRequest;
use Swerve\Http\SapiRequest;
use Swerve\Swerve;

/**
 * Serves an exchange under sapi() with a closure taking a PSR-7 ServerRequestInterface and
 * returning a ResponseInterface, as a PSR-7 application runs under php-fpm: the request is made
 * from the superglobals, its body is php://input, and the response is written to the
 * ClientRequest, with the headers PHP set itself (a session's cookie) that the response does not
 * name. PHP's own head and flush() are not the response: a coroutine of the handler that fills
 * the body and calls flush() commits nothing early (#107); output with echo throws.
 *
 * A `101 Switching Protocols` response to a request with an Upgrade header takes the connection,
 * as {@see Swerve::upgrade()} does: the 101 goes out with the response's headers, the response
 * body is copied to the connection until it ends, and the request body is what the client sends
 * after the 101, until it stops sending.
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
        SapiRequest::psr();
        $headers  = $client->getRequestHeaders();
        $upgrade  = isset($headers['upgrade']) ? new UnbufferedStream() : null;
        $response = $app(self::request($client, $headers, $upgrade));
        if (!$response instanceof ResponseInterface) {
            throw new \TypeError('A PSR-7 handler must return a ' . ResponseInterface::class . ', got ' . \get_debug_type($response));
        }
        if (101 === $response->getStatusCode()) {
            if (null === $upgrade) {
                throw new \LogicException('A PSR-7 handler answered 101 to a request without an Upgrade header');
            }
            self::upgrade($response, $upgrade);

            return;
        }
        self::send($client, $response);
    }

    /** @param array<string, list<string>> $headers */
    private static function request(ClientRequest $client, array $headers, ?UnbufferedStream $upgrade): ServerRequestInterface
    {
        $form = (bool) \preg_match('#^(application/x-www-form-urlencoded|multipart/form-data)\b#i', $headers['content-type'][0] ?? '');

        return new ServerRequest(
            $client->getMethod(),
            $client->getTarget(),
            null === $upgrade ? static fn () => new ResourceStream(\fopen('php://input', 'rb')) : static fn () => $upgrade,
            $headers,
            $_GET,
            $_SERVER,
            $_COOKIE,
            self::files($_FILES),
            $form ? $_POST : null,
            [],
            $client->getProtocolVersion(),
        );
    }

    /** $_FILES as a tree of UploadedFile, nested as the field names nest. */
    private static function files(array $files): array
    {
        $tree = [];
        foreach ($files as $name => $file) {
            $tree[$name] = self::file($file['tmp_name'], $file['name'], $file['type'], $file['size'], $file['error']);
        }

        return $tree;
    }

    private static function file(mixed $path, mixed $name, mixed $type, mixed $size, mixed $error): UploadedFile|array
    {
        if (\is_array($error)) {
            $tree = [];
            foreach ($error as $key => $code) {
                $tree[$key] = self::file($path[$key], $name[$key], $type[$key], $size[$key], $code);
            }

            return $tree;
        }

        return new UploadedFile(\UPLOAD_ERR_OK === $error ? $path : EmptyStream::create(), $name, $type, $size, $error);
    }

    /** The connection goes on with the response body out, and what the client sends into $in. */
    private static function upgrade(ResponseInterface $response, UnbufferedStream $in): void
    {
        $headers = $response->getHeaders();
        $out     = $response->getBody();
        Swerve::upgrade(static function (ClientRequest $client) use ($headers, $out, $in): void {
            $client->sendResponseHeaders(101, $headers);
            phasync::go(static function () use ($client, $in): void {
                try {
                    while ('' !== $chunk = $client->read()) {
                        $in->append($chunk);
                    }
                } finally {
                    $in->end();
                }
            });
            while (!$out->eof()) {
                $client->write($out->read(self::CHUNK));
            }
            $client->end();
        });
    }

    private static function send(ClientRequest $client, ResponseInterface $response): void
    {
        $headers = $response->getHeaders();
        $named   = \array_change_key_case(\array_fill_keys(\array_keys($headers), true));
        // What PHP was told (session_start()'s cookie, setcookie(), X-Powered-By), unless the response names it
        foreach (\headers_list() as $line) {
            [$name, $value] = \explode(':', $line, 2) + [1 => ''];
            if (!isset($named[\strtolower($name = \trim($name))])) {
                $headers[$name][] = \ltrim($value);
            }
        }
        $body = $response->getBody();
        $size = $body->getSize();
        if (null !== $size && $size > 0 && !$response->hasHeader('Content-Length')) {
            $headers['Content-Length'] = [(string) $size];
        }
        $client->sendResponseHeaders($response->getStatusCode(), $headers, '' === $response->getReasonPhrase() ? null : $response->getReasonPhrase());
        if ('HEAD' === $client->getMethod()) {
            return;
        }
        if ($body->isSeekable()) {
            $body->rewind(); // a response is built by writing to its body, which leaves it at the end
        }
        while (!$body->eof()) {
            $client->write($body->read(self::CHUNK));
        }
    }
}
