<?php

namespace Swerve\Psr;

use phasync\Psr\EmptyStream;
use phasync\Psr\ResourceStream;
use phasync\Psr\ServerRequest;
use phasync\Psr\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\ClientRequest;

/**
 * Serves an exchange under sapi() with a closure taking a PSR-7 ServerRequestInterface and
 * returning a ResponseInterface, as a PSR-7 application runs under php-fpm: the request is made
 * from the superglobals, its body is php://input, and the response goes out through header() and
 * echo.
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
        self::send($response);
    }

    private static function request(ClientRequest $client): ServerRequestInterface
    {
        $headers = $client->getRequestHeaders();
        $form    = (bool) \preg_match('#^(application/x-www-form-urlencoded|multipart/form-data)\b#i', $headers['content-type'][0] ?? '');

        return new ServerRequest(
            $client->getMethod(),
            $client->getTarget(),
            static fn () => new ResourceStream(\fopen('php://input', 'rb')),
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
