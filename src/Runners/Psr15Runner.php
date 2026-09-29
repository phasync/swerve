<?php

namespace Swerve\Runners;

use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Swerve\ConnectionInterface;
use phasync\Psr\ComposableStream;
use Swerve\Http\FormBody;
use phasync\Psr\ServerRequest;
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
        // The request as HTTP mode's: FastCGI passes the body's type and length as parameters,
        // which PSR-7 has as headers
        $params  = $connection->getServerParams();
        $headers = $connection->getRequestHeaders();
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $param => $header) {
            if ('' !== ($params[$param] ?? '')) {
                $headers[$header] = [$params[$param]];
            }
        }
        $method  = $connection->getRequestMethod();
        $body    = new ComposableStream(readFunction: $connection->read(...), eofFunction: $connection->eof(...));
        $target  = $connection->getRequestTarget();
        $version = $connection->getProtocolVersion();
        $request = null === ($form = FormBody::for($method, $headers['content-type'][0] ?? '', $body))
            ? new ServerRequest($method, $target, $body, $headers, null, $params, protocolVersion: $version)
            // Form data is parsed when first asked for
            : new ServerRequest($method, $target, $form->input(...), $headers, null, $params, [], $form->files(...), $form->fields(...), protocolVersion: $version);
        if (isset($headers['cookie'])) {
            $request = $request->withCookieParams(ServerRequest::cookies(\implode('; ', $headers['cookie'])));
        }
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
