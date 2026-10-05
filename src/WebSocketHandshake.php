<?php

namespace Swerve;

/**
 * The decision of {@see WebSocket::handshake()}: the answer to a WebSocket handshake.
 *
 * Adapter-facing: a framework adapter builds its own response from it. Accepted (`101 ===
 * $status`), `$headers` are those of the `101` response and `$subprotocol` is the one chosen.
 * Refused (426, 400 or 403), `$headers` and `$body` are the complete final response, as swerve
 * itself answers.
 */
final class WebSocketHandshake
{
    /**
     * @param array<string, string> $headers lowercase name => value
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body = '',
        public readonly ?string $subprotocol = null,
    ) {
    }

    /** Whether the connection may be upgraded: the status is 101. */
    public function accepted(): bool
    {
        return 101 === $this->status;
    }
}
