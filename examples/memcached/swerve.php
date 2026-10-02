<?php

/*
 * A memcached server (text protocol) in every worker, storing in Swerve::cache(). Each worker
 * listens on the same port with SO_REUSEPORT, so the workers share one cache and any memcached
 * client works. The page on swerve's HTTP port shows the port and the counters of the worker
 * that served it.
 *
 *     MEMCACHED_PORT=11211 vendor/bin/swerve examples/memcached/swerve.php
 *     printf 'set a 0 0 1\r\nx\r\nget a\r\n' | nc localhost 11211
 */

use Example\MemcachedServer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use phasync\Psr\Response;

require_once __DIR__ . '/MemcachedServer.php';

$port      = (int) (\getenv('MEMCACHED_PORT') ?: 11211);
$memcached = new MemcachedServer($port);
$memcached->start();

return new class($memcached, $port) implements RequestHandlerInterface {
    public function __construct(private MemcachedServer $memcached, private int $port)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $text = "memcached protocol on port {$this->port}\n";
        foreach ($this->memcached->stats() as $name => $value) {
            $text .= "$name $value\n";
        }

        return new Response(200, ['Content-Type' => 'text/plain'], $text);
    }
};
