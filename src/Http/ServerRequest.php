<?php

namespace Swerve\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The PSR-7 request a server gives the application, the same for every protocol.
 *
 * A server supplies what its protocol parsed (method, target, headers, body stream, server
 * params). The cookies and the form data are derived when first asked for: `getParsedBody()` and
 * `getUploadedFiles()` give a POST form's fields and files as PHP's `$_POST` and `$_FILES`, and
 * `getParsedBody()` is null for any other body, which is left raw in `getBody()`. The application
 * receives one from the server; it is not meant to create them.
 *
 * ```php
 * public function handle(ServerRequestInterface $request): ResponseInterface
 * {
 *     $name = $request->getParsedBody()['name'] ?? $request->getCookieParams()['name'] ?? 'guest';
 *     $json = json_decode((string) $request->getBody(), true);   // any other body is read raw
 *     ...
 * }
 * ```
 *
 * @see Swerve\Dispatcher
 */
final class ServerRequest extends \phasync\Psr\ServerRequest
{
    private bool $cookiesFromHeader = true;

    /**
     * Make the request of a protocol server, which gives the headers in the form this keeps them in.
     *
     * Nothing is translated here: the application is not meant to create requests.
     *
     * @param string                  $method       the HTTP method
     * @param string                  $target       the request target, as on the request line
     * @param StreamInterface         $body         the body stream
     * @param array<string, string[]> $headers      lower-case name => list of values
     * @param array<string, string>   $headerNames  lower-case name => the name as sent
     * @param array<string, mixed>    $server       the server params ($_SERVER's request part)
     * @param string                  $version      the protocol version, such as `1.1`
     * @param bool                    $upgrade      the request asks for a protocol upgrade: its body is never parsed as a form
     * @param array<string, mixed>    $attributes   the server's own request attributes
     */
    public function __construct(string $method, string $target, StreamInterface $body, array $headers, array $headerNames, array $server, string $version, bool $upgrade = false, array $attributes = [])
    {
        $form = 'POST' === $method && !$upgrade && isset($headers['content-type']) ? FormBody::for($method, $headers['content-type'][0], $body) : null;
        if (null === $form) {
            parent::__construct($method, $target, $body, [], null, $server, attributes: $attributes, protocolVersion: $version);
        } else {
            // Form data is parsed when first asked for
            parent::__construct($method, $target, $form->input(...), [], null, $server, [], $form->files(...), $form->fields(...), $attributes, $version);
        }
        $this->headers     = $headers;
        $this->headerCases = $headerNames;
    }

    public function getCookieParams(): array
    {
        if ($this->cookiesFromHeader) {
            $this->cookiesFromHeader = false;
            $this->cookieParams      = [] === ($header = $this->getHeader('cookie')) ? [] : self::cookies(\implode('; ', $header));
        }

        return $this->cookieParams;
    }

    public function withCookieParams($cookies): ServerRequestInterface
    {
        $c                    = parent::withCookieParams($cookies);
        $c->cookiesFromHeader = false;

        return $c;
    }
}
