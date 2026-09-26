<?php
namespace Swerve\Http\Message;

use Psr\Http\Message\ResponseInterface;

/**
 * Representation of an outgoing, server-side response.
 *
 * Per the HTTP specification, this interface includes properties for
 * each of the following:
 *
 * - Protocol version
 * - Status code and reason phrase
 * - Headers
 * - Message body
 *
 * Responses are considered immutable; all methods that might change state MUST
 * be implemented such that they retain the internal state of the current
 * message and return an instance that contains the changed state.
 */
trait ResponseTrait {
    use MessageTrait;

    protected int $statusCode;
    protected string $reasonPhrase;

    /**
     * Configure the response trait
     *
     * @param mixed $body               Body as an instance of Psr\Http\Message\StreamInterface or the types accepted by {$see Stream::create()}
     * @param array $headers            Array of header names => values
     * @param int $statusCode           HTTP status code
     * @param string $reasonPhrase      The HTTP reason phrase
     * @param string $protocolVersion   The HTTP protocol version, typically "1.1" or "1.0"
     */
    protected function ResponseTrait(mixed $body, array $headers=[], int $statusCode=200, ?string $reasonPhrase=null, string $protocolVersion="1.1") {
        $this->statusCode = $statusCode;
        if ($reasonPhrase === null) {
            $this->reasonPhrase = self::getDefaultReasonPhrase($statusCode, '');
        } else {
            $this->reasonPhrase = $reasonPhrase;
        }
        $this->MessageTrait(Stream::cast($body), $headers, $protocolVersion);
    }

    /**
     * Gets the response status code.
     *
     * The status code is a 3-digit integer result code of the server's attempt
     * to understand and satisfy the request.
     *
     * @return int Status code.
     */
    public function getStatusCode(): int {
        return $this->statusCode;
    }

    /**
     * Return an instance with the specified status code and, optionally, reason phrase.
     *
     * If no reason phrase is specified, implementations MAY choose to default
     * to the RFC 7231 or IANA recommended reason phrase for the response's
     * status code.
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * updated status and reason phrase.
     *
     * @see http://tools.ietf.org/html/rfc7231#section-6
     * @see http://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml
     * @param int $code The 3-digit integer result code to set.
     * @param string $reasonPhrase The reason phrase to use with the
     *     provided status code; if none is provided, implementations MAY
     *     use the defaults as suggested in the HTTP specification.
     * @return static
     * @throws \InvalidArgumentException For invalid status code arguments.
     */
    public function withStatus(int $code, string $reasonPhrase = ''): \Psr\Http\Message\ResponseInterface {
        $c = clone $this;
        $c->statusCode = $code;
        if ($reasonPhrase === '') {
            $c->reasonPhrase = self::getDefaultReasonPhrase($code, '');
        } else {
            $c->reasonPhrase = $reasonPhrase;
        }
        return $c;
    }

    /**
     * Gets the response reason phrase associated with the status code.
     *
     * Because a reason phrase is not a required element in a response
     * status line, the reason phrase value MAY be empty. Implementations MAY
     * choose to return the default RFC 7231 recommended reason phrase (or those
     * listed in the IANA HTTP Status Code Registry) for the response's
     * status code.
     *
     * @see http://tools.ietf.org/html/rfc7231#section-6
     * @see http://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml
     * @return string Reason phrase; must return an empty string if none present.
     */
    public function getReasonPhrase(): string {
        return $this->reasonPhrase;
    }

    protected static function getDefaultReasonPhrase(int $statusCode, string $defaultPhrase=''): ?string {
        if (isset(Response::PHRASES[$statusCode])) {
            return Response::PHRASES[$statusCode];
        }
        return $defaultPhrase;
    }
}
