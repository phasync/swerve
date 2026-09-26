<?php
namespace Swerve\Http\Message;

use Psr\Http\Message\{
    ServerRequestInterface,
    UploadedFileInterface,
    StreamInterface,
    UriInterface
};

/**
 * Representation of an incoming, server-side HTTP request.
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
 * Additionally, it encapsulates all data as it has arrived at the
 * application from the CGI and/or PHP environment, including:
 *
 * - The values represented in $_SERVER.
 * - Any cookies provided (generally via $_COOKIE)
 * - Query string arguments (generally via $_GET, or as parsed via parse_str())
 * - Upload files, if any (as represented by $_FILES)
 * - Deserialized body parameters (generally from $_POST)
 *
 * $_SERVER values MUST be treated as immutable, as they represent application
 * state at the time of request; as such, no methods are provided to allow
 * modification of those values. The other values provide such methods, as they
 * can be restored from $_SERVER or the request body, and may need treatment
 * during the application (e.g., body parameters may be deserialized based on
 * content type).
 *
 * Additionally, this interface recognizes the utility of introspecting a
 * request to derive and match additional parameters (e.g., via URI path
 * matching, decrypting cookie values, deserializing non-form-encoded body
 * content, matching authorization headers to users, etc). These parameters
 * are stored in an "attributes" property.
 *
 * Requests are considered immutable; all methods that might change state MUST
 * be implemented such that they retain the internal state of the current
 * message and return an instance that contains the changed state.
 */
class ServerRequest extends Request implements ServerRequestInterface {
    use ServerRequestTrait;

    /**
     * Construct a ServerRequest instance.
     *
     * @param string $method                            Case-sensitive method.
     * @param string $requestTarget                     Request target (e.g., "/path?query=value")
     * @param string|resource|StreamInterface $body     Body
     * @param array $headers                            Array of header names => values
     * @param array|null $queryParams                   Query params override (null = derive from request target)
     * @param array $serverParams                       Array of server params like $_SERVER
     * @param array $cookieParams                       Array of cookie params like $_COOKIE
     * @param UploadedFileInterface[] $uploadedFiles    Array of uploaded file instances
     * @param null|object|array $parsedBody             Deserialized body data, typically an object or array.
     * @param array $attributes                         Attributes derived from the request
     * @param string $protocolVersion                   The HTTP protocol version, typically "1.1" or "1.0"
     */
    public function __construct(
        string $method,
        string $requestTarget,
        mixed $body,
        array $headers=[],
        ?array $queryParams=null,
        array $serverParams=[],
        array $cookieParams=[],
        array $uploadedFiles=[],
        mixed $parsedBody=null,
        array $attributes=[],
        string $protocolVersion="1.1"
    ) {
        $this->ServerRequestTrait($method, $requestTarget, $body, $headers, $queryParams, $serverParams, $cookieParams, $uploadedFiles, $parsedBody, $attributes, $protocolVersion);
    }
}
