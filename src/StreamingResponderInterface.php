<?php

namespace Swerve;

/**
 * A responder that can send a response while the application still produces it: the output of code
 * written for PHP-FPM (echo, header()) goes to the client as it is made, not when the handler has
 * returned. {@see Swerve::virtualize()} makes the Dispatcher use it.
 *
 * The head is sent once, before the first byte of the body; {@see streamEnd()} ends the response
 * in place of {@see ResponderInterface::respond()}.
 *
 * @see Swerve\Dispatcher
 */
interface StreamingResponderInterface extends ResponderInterface
{
    /**
     * Send the head of the response. Called once, before the first {@see stream()}.
     *
     * @param array<string, list<string>> $headers
     */
    public function streamHead(int $status, string $reason, array $headers): void;

    /**
     * Send a piece of the body, as it is.
     *
     * @return bool false when the client has gone: nothing more is sent
     */
    public function stream(string $data): bool;

    /** Whether the client has gone, as connection_aborted() asks. */
    public function streamGone(): bool;

    /**
     * The response is complete: end it.
     *
     * @return mixed what {@see ResponderInterface::respond()} returns
     */
    public function streamEnd(): mixed;
}
