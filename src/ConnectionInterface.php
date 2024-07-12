<?php
namespace Swerve;

/**
 * Interface for handling HTTP connections abstracted from the underlying protocol (e.g., FastCGI, HTTP/1, HTTP/2).
 *
 * @package Swerve
 */
interface ConnectionInterface {

    /**
     * Has the connection been aborted?
     * 
     * @return bool 
     */
    public function isAborted(): bool;

    /**
     * Returns the HTTP protocol version.
     *
     * @return string
     */
    public function getProtocolVersion(): string;

    /**
     * Returns the client IP address.
     * 
     * @return string 
     */
    public function getClientIp(): string;

    /**
     * Returns the client port number.
     * 
     * @return string 
     */
    public function getClientPort(): string;

    /**
     * Returns the HTTP request method (e.g., GET, POST), literally as sent from the client.
     *
     * @return string 
     */
    public function getRequestMethod(): string;
    
    /**
     * Returns a list of all HTTP request headers.
     *
     * @return array
     */
    public function getRequestHeaders(): array;

    /**
     * Returns the request target (the part following the request method)
     * 
     * @return string 
     */
    public function getRequestTarget(): string;

    /**
     * Get server parameters (generally environment variables according to
     * the CGI/1.1 specification)
     * 
     * @return array 
     */
    public function getServerParams(): array;

    /**
     * Send the HTTP response head back to the client. The headers should be sent immediately
     * without buffering, and after this it is not permitted to send the response head again.
     * 
     * @param array $headers 
     * @param int $statusCode 
     * @param string $reasonPhrase 
     */
    public function sendHead(array $headers, int $statusCode = 200, string $reasonPhrase = ''): void;

    /**
     * Read a chunk of data from the request body. Reading blocks until data is available
     * or EOF is reached.
     * 
     * @param int $maxLength 
     * @return string 
     */
    public function read(int $maxLength): string;

    /**
     * Checks if the end of the client request body has been reached.
     * For keep-alive connections, this pertains to the current request, not the connection itself.
     * 
     * @return bool 
     */
    public function eof(): bool;

    /**
     * Writes a chunk of data to the response body. Writing blocks until the data is
     * accepted.
     *
     * @param string $chunk
     * @return void
     */
    public function write(string $chunk): void;

    /**
     * Ends the response. No further writes can be made at this point.
     *
     * @return void
     */
    public function end(): void;
}
