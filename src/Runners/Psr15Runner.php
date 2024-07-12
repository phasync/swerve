<?php

namespace Swerve\Runners;

use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Swerve\ConnectionInterface;
use Swerve\Psr15\ServerRequest;
use Swerve\SwerveInterface;

/**
 * Implementation providing capability of running PSR-15 RequestHandlers
 * 
 * @package Swerve
 */
final class Psr15Runner implements SwerveInterface
{

    private RequestHandlerInterface $requestHandler;

    /**
     * Set the HTTP request handler.
     * 
     * @param RequestHandlerInterface $requestHandler 
     */
    public function __construct(RequestHandlerInterface $requestHandler)
    {
        $this->requestHandler = $requestHandler;
    }

    /**
     * Handle the connection via the PSR-15 HTTP Request Handler.
     * 
     * @param ConnectionInterface $connection 
     */
    public function handleConnection(ConnectionInterface $connection): void
    {
        $request = new ServerRequest($connection);
        $response = $this->requestHandler->handle($request);
        $headers = [];
        $map = [];
        foreach ($response->getHeaders() as $name => $values) {
            $lName = \strtolower($name);
            foreach ($values as $value) {
                $headers[] = \sprintf("%s: %s", $name, $value);
            }
            $map[$lName] = \trim($values[0]);
        }
        $stream = $response->getBody();
        if (empty($map["content-length"])) {
            $streamLength = $stream->getSize();
            if ($streamLength !== null) {
                $map["content-length"] = $streamLength;
                $headers[] = "Content-Length: " . $streamLength;
            }
        }
        if (empty($map["content-type"])) {
            $map["content-type"] = "text/html; charset=utf-8";
            $headers[] = "Content-Type: text/html; charset=utf-8";
        }
        if (empty($map['connection'])) {
            $map['connection'] = 'keep-alive';
            $headers[] = 'Connection: keep-alive';
        }
        $connection->sendHead($headers, $response->getStatusCode(), $response->getReasonPhrase());
        try {
            $stream->rewind();
        } catch (RuntimeException) {
        }
        while (!$stream->eof()) {
            $chunk = $stream->read(65536);
            $connection->write($chunk);
        }
        $connection->end();
    }
}
