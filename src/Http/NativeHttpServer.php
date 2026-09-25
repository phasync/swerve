<?php

namespace Swerve\Http;

use phasync;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * --native-http: a worker serving HTTP/1.1 itself, with no proxy in front. Every worker
 * listens on the same address (SO_REUSEPORT), and the kernel spreads new connections
 * over them.
 */
final class NativeHttpServer
{
    public function __construct(
        private readonly string $address,
        private readonly RequestHandlerInterface $handler,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Accept and serve connections until the process ends. Call from inside phasync::run().
     */
    public function run(): void
    {
        $listener = phasync\Net\listen($this->address);
        $this->logger->info('Serving HTTP at {address}', ['address' => $listener->addr()]);
        foreach ($listener as $peer => $socket) {
            $connection = new NativeHttpConnection($socket, $peer, $this->handler, $this->logger);
            phasync::go($connection->serve(...));
        }
    }
}
