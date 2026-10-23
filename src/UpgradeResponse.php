<?php

namespace Swerve;

use phasync\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Psr\DuplexBody;

/**
 * The PSR-7 response for a raw `101` upgrade that is not a WebSocket: a custom protocol tunnelled
 * over the connection, such as swerve's own test fixtures' `Upgrade: echo`.
 *
 * ```php
 * return UpgradeResponse::from($request, ['Upgrade' => 'echo', 'Connection' => 'Upgrade'], function (Duplex $connection) {
 *     while ('' !== $chunk = $connection->read()) {
 *         $connection->write($chunk);
 *     }
 * });
 * ```
 *
 * `$handler` is given a {@see \phasync\Net\Duplex} once {@see \Swerve\Psr\Bridge} starts reading the
 * `101`'s body (lazily, on its first byte): reading it is the inbound side (the request's body, so
 * a middleware that wrapped it is honoured), writing it is the outbound side. The connection ends
 * when the handler returns (or throws, logged as any other request failure would be).
 *
 * @see WebSocketResponse for the WebSocket protocol specifically
 */
final class UpgradeResponse extends Response
{
    private function __construct(array $headers, DuplexBody $body)
    {
        parent::__construct(101, $headers, $body);
    }

    /**
     * @param array<string, string|list<string>> $headers the `101`'s headers, such as `Upgrade` and `Connection`
     * @param callable(\phasync\Net\Duplex): void $handler
     */
    public static function from(ServerRequestInterface $request, array $headers, callable $handler): ResponseInterface
    {
        return new self($headers, new DuplexBody($request->getBody(), $handler(...)));
    }
}
