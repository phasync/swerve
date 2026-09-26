<?php
namespace Swerve\Http\Message;

use Psr\Http\Message\{
    RequestInterface,
    UriInterface
};

/**
 * Representation of an outgoing, client-side request.
 *
 * Per the HTTP specification, this interface includes properties for
 * each of the following:
 *
 * - Protocol version
 * - HTTP method
 * - URI
 * - Headers
 * - Message body
 *
 * During construction, implementations MUST attempt to set the Host header from
 * a provided URI if no Host header is provided.
 *
 * Requests are considered immutable; all methods that might change state MUST
 * be implemented such that they retain the internal state of the current
 * message and return an instance that contains the changed state.
 */
trait RequestTrait {
    use MessageTrait;

    protected string $method;
    protected string $requestTarget;
    protected ?UriInterface $uriOverride = null;

    public function __clone() {
        if ($this->uriOverride !== null) {
            $this->uriOverride = clone $this->uriOverride;
        }
    }

    /**
     * Configure the request.
     *
     * @param string $method                        Case-sensitive method.
     * @param string $requestTarget                 Request target (e.g., "/path?query=value")
     * @param string|resource|StreamInterface $body Body
     * @param string[][] $headers                   Array of header names => values
     * @param string $protocolVersion               The HTTP protocol version, typically "1.1" or "1.0"
     */
    protected function RequestTrait(string $method, string $requestTarget, mixed $body, array $headers=[], string $protocolVersion='1.1') {
        $this->method = $method;
        $this->requestTarget = $requestTarget;

        $this->MessageTrait(Stream::cast($body), $headers, $protocolVersion);
    }

    /**
     * Retrieves the message's request target.
     *
     * Retrieves the message's request-target either as it will appear (for
     * clients), as it appeared at request (for servers), or as it was
     * specified for the instance (see withRequestTarget()).
     *
     * The request target is stored directly and returned as-is. This method
     * does not construct the target from the URI.
     *
     * @return string
     */
    public function getRequestTarget(): string {
        return $this->requestTarget;
    }

    /**
     * Return an instance with the specific request-target.
     *
     * If the request needs a non-origin-form request-target — e.g., for
     * specifying an absolute-form, authority-form, or asterisk-form —
     * this method may be used to create an instance with the specified
     * request-target, verbatim.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * changed request target.
     *
     * @see http://tools.ietf.org/html/rfc7230#section-5.3 (for the various
     *     request-target forms allowed in request messages)
     * @param string $requestTarget
     * @return static
     */
    public function withRequestTarget(string $requestTarget): RequestInterface {
        $c = clone $this;
        // Freeze the URI before changing the request target (PSR-7: URI and request-target are independent)
        if ($c->uriOverride === null) {
            $c->uriOverride = $this->getUri();
        }
        $c->requestTarget = $requestTarget;
        return $c;
    }

    /**
     * Retrieves the HTTP method of the request.
     *
     * @return string Returns the request method.
     */
    public function getMethod(): string {
        return $this->method;
    }

    /**
     * Return an instance with the provided HTTP method.
     *
     * While HTTP method names are typically all uppercase characters, HTTP
     * method names are case-sensitive and thus implementations SHOULD NOT
     * modify the given string.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * changed request method.
     *
     * @param string $method Case-sensitive method.
     * @return static
     * @throws \InvalidArgumentException for invalid HTTP methods.
     */
    public function withMethod(string $method): RequestInterface {
        $c = clone $this;
        $c->method = $method;
        return $c;
    }

    /**
     * Retrieves the URI instance.
     *
     * This method MUST return a UriInterface instance.
     *
     * If a URI override was set via withUri(), returns that instance.
     * Otherwise, constructs a new URI from the request target and Host header.
     *
     * Returns a relative URI if no Host header is present.
     *
     * @see http://tools.ietf.org/html/rfc3986#section-4.3
     * @return UriInterface Returns a UriInterface instance
     *     representing the URI of the request.
     */
    public function getUri(): UriInterface {
        if ($this->uriOverride !== null) {
            return $this->uriOverride;
        }

        // Construct from request target + headers
        $uri = $this->requestTarget;

        // Add scheme and host if Host header exists
        if ($host = $this->getHeaderLine('Host')) {
            // Default to http:// scheme (ServerRequest overrides this with HTTPS detection)
            $uri = "http://{$host}{$this->requestTarget}";
        }
        // else: return relative URI (just request target)

        return new Uri($uri);
    }

    /**
     * Returns an instance with the provided URI.
     *
     * This method MUST update the Host header of the returned request by
     * default if the URI contains a host component. If the URI does not
     * contain a host component, any pre-existing Host header MUST be carried
     * over to the returned request.
     *
     * You can opt-in to preserving the original state of the Host header by
     * setting `$preserveHost` to `true`. When `$preserveHost` is set to
     * `true`, this method interacts with the Host header in the following ways:
     *
     * - If the Host header is missing or empty, and the new URI contains
     *   a host component, this method MUST update the Host header in the returned
     *   request.
     * - If the Host header is missing or empty, and the new URI does not contain a
     *   host component, this method MUST NOT update the Host header in the returned
     *   request.
     * - If a Host header is present and non-empty, this method MUST NOT update
     *   the Host header in the returned request.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * new UriInterface instance.
     *
     * @see http://tools.ietf.org/html/rfc3986#section-4.3
     * @param UriInterface $uri New request URI to use.
     * @param bool $preserveHost Preserve the original state of the Host header.
     * @return static
     */
    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface {
        $host = $uri->getHost();
        if (($preserveHost && $this->hasHeader('host')) || !$host) {
            $c = clone $this;
        } else {
            $c = $this->withHeader('Host', $host);
        }
        $c->uriOverride = clone $uri;
        return $c;
    }

    /**
     * Get the query string from the request target.
     *
     * Returns the query string portion of the request target (everything after '?').
     * If a URI override is set via withUri(), returns the query from that URI instead.
     *
     * @return string Query string (without leading '?'), or empty string if no query
     */
    public function getQuery(): string {
        if ($this->uriOverride !== null) {
            return $this->uriOverride->getQuery();
        }

        $target = $this->requestTarget;
        if (str_contains($target, '?')) {
            return substr($target, strpos($target, '?') + 1);
        }

        return '';
    }
}
