<?php

namespace Swerve;

/**
 * The request can't be upgraded as asked: a WebSocket closure for a request that is no valid
 * WebSocket handshake, say. The code is the HTTP status that answers it (400, 403 or 426); an
 * application that doesn't catch it has the client answered so, without a log entry.
 *
 * ```php
 * try {
 *     Swerve::upgrade(static function (WebSocket $ws): void { ... });
 * } catch (UpgradeException) {
 *     echo 'This address speaks WebSocket';
 * }
 * ```
 *
 * @topic request-handlers
 */
final class UpgradeException extends \RuntimeException
{
    /**
     * @param array<string, string|list<string>> $headers the refusal's response headers
     *
     * @internal
     */
    public function __construct(string $message, int $status, public readonly array $headers = [], public readonly string $body = '')
    {
        parent::__construct($message, $status);
    }
}
