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
use Swerve\ClientRequest;
use Swerve\RequestHandler;

require_once __DIR__ . '/MemcachedServer.php';

$port      = (int) (\getenv('MEMCACHED_PORT') ?: 11211);
$memcached = new MemcachedServer($port);
$memcached->start();

return new RequestHandler(static function (ClientRequest $request) use ($memcached, $port) {
    $text = "memcached protocol on port $port\n";
    foreach ($memcached->stats() as $name => $value) {
        $text .= "$name $value\n";
    }
    $request->sendResponseHeaders(200, ['Content-Type' => 'text/plain']);
    $request->write($text);
});
