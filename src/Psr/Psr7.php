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
use Swerve\Swerve;

/**
 * Serves an exchange under sapi() with a closure taking a PSR-7 ServerRequestInterface and
 * returning a ResponseInterface, as a PSR-7 application runs under php-fpm: the request is made
 * from the superglobals, its body is php://input, and the response goes out through header() and
 * echo.
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
        self::send($response);
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

    private static function send(ResponseInterface $response): void
    {
        \http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                \header("$name: $value", false);
            }
        }
        $body = $response->getBody();
        $size = $body->getSize();
        if (null !== $size && $size > 0 && !$response->hasHeader('Content-Length')) {
            \header("Content-Length: $size");
        }
        if ('HEAD' === $_SERVER['REQUEST_METHOD']) {
            return;
        }
        if ($body->isSeekable()) {
            $body->rewind(); // a response is built by writing to its body, which leaves it at the end
        }
        while (!$body->eof()) {
            echo $body->read(self::CHUNK);
        }
    }
}
