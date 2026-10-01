<?php

namespace Swerve\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The PSR-7 request a server gives the application, the same for every protocol: a server
 * supplies what its protocol parsed (method, target, headers, body stream, server params), and
 * the form data and the cookies are derived from the headers, when first asked for.
 */
final class ServerRequest extends \phasync\Psr\ServerRequest
{
    private bool $cookiesFromHeader = true;

    /**
     * A server gives the headers in the form this keeps them in, so nothing is translated here:
     * the application is not meant to create requests.
     *
     * @param array<string, string[]> $headers    lower-case name => list of values
     * @param array<string, string>   $headerNames lower-case name => the name as sent
     * @param array<string, mixed>    $server     the server params ($_SERVER's request part)
     * @param bool                    $upgrade    the request asks for a protocol upgrade: its body is never parsed as a form
     * @param array<string, mixed>    $attributes the server's own request attributes
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
