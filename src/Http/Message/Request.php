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
class Request implements RequestInterface {
    use RequestTrait;

    /**
     * Configure the request.
     *
     * @param string $method                        Case-sensitive method.
     * @param string $requestTarget                 Request target (e.g., "/path?query=value")
     * @param string|resource|StreamInterface $body Body
     * @param string[][] $headers                   Array of header names => values
     * @param string $protocolVersion               The HTTP protocol version, typically "1.1" or "1.0"
     */
    public function __construct(string $method, string $requestTarget, mixed $body='', array $headers=[], string $protocolVersion='1.1') {
        $this->RequestTrait($method, $requestTarget, $body, $headers, $protocolVersion);
    }

    /**
     * Create a Request from a URI
     *
     * Convenience factory for creating outgoing HTTP requests from URIs.
     * Useful for HTTP clients, testing, and when thinking in terms of full URIs.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string|\Stringable|UriInterface $uri Full or relative URI
     * @return RequestInterface
     */
    public static function create(string $method, string|\Stringable|UriInterface $uri): RequestInterface
    {
        // Convert to UriInterface if needed
        if (!($uri instanceof UriInterface)) {
            $uri = new Uri((string)$uri);
        }

        // Extract request target (path + query)
        $requestTarget = $uri->getPath() ?: '/';
        if ($query = $uri->getQuery()) {
            $requestTarget .= '?' . $query;
        }

        // Extract Host header if present
        $headers = [];
        if ($host = $uri->getHost()) {
            $headers['Host'] = $host;
            if ($port = $uri->getPort()) {
                $headers['Host'] .= ':' . $port;
            }
        }

        $request = new self($method, $requestTarget, '', $headers);

        // If full URI was provided, store it as override to preserve scheme
        if ($uri->getScheme() || $uri->getHost()) {
            $request = $request->withUri($uri);
        }

        return $request;
    }
}
