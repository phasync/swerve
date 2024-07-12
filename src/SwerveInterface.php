<?php

namespace Swerve;

/**
 * Interface responsible for launching the application and sending a response
 * back to the client.
 */
interface SwerveInterface
{
    /**
     * Handle an incoming connection and handle the communication with the client.
     */
    public function handleConnection(ConnectionInterface $connection): void;
}
