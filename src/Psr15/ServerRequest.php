<?php
namespace Swerve\Psr15;

use Nyholm\Psr7\MessageTrait;
use Nyholm\Psr7\RequestTrait;
use Nyholm\Psr7\Uri;
use phasync\Util\StringBuffer;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\StreamInterface;
use Swerve\ConnectionInterface;

final class ServerRequest implements ServerRequestInterface {
    use MessageTrait;
    use RequestTrait;

    private ConnectionInterface $connection;
    private ?array $cookieParams = null;
    private ?array $queryParams = null;
    private array $uploadedFiles = [];
    private ?array $parsedBody = null;
    private bool $hasParsedBody = false;
    private array $attributes = [];
    private $requestTarget = null;
    private ?string $requestMethod = null;
    private $uri = null;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    public function getServerParams(): array {
        return $this->connection->getServerParams();
    }

    public function getCookieParams(): array {
        if ($this->cookieParams === null) {
            $this->cookieParams = [];
            $cookieHeaders = $this->connection->getRequestHeaders()['cookie'] ?? [];
            foreach ($cookieHeaders as $value) {
                $cookies = explode('; ', $value);            
                foreach ($cookies as $cookie) {
                    list($name, $value) = explode('=', $cookie, 2);
                    $this->cookieParams[$name] = urldecode($value);
                }
            }
        }
        return $this->cookieParams;
    }

    public function withCookieParams(array $cookies): ServerRequestInterface {
        $c = clone $this;
        $c->cookieParams = $cookies;
        return $c;
    }

    public function getUri(): UriInterface {
        if ($this->uri === null) {
            $serverParams = $this->getServerParams();
            $scheme = 'http';
            if (!empty($serverParams['REQUEST_SCHEME'])) {
                $scheme = $serverParams['REQUEST_SCHEME'];
            }
            $host = '127.0.0.1';
            if (!empty($serverParams['SERVER_NAME'])) {
                $host = $serverParams['SERVER_NAME'];
            }
            if ($this->hasHeader('host')) {
                $host = $this->getHeaderLine('host');
            }
            $port = 80;
            if (!empty($serverParams['SERVER_PORT'])) {
                $port = $serverParams['SERVER_PORT'];
            }
            $path = '/';
            if (!empty($serverParams['REQUEST_URI'])) {
                $path = $serverParams['REQUEST_URI'];
            } elseif (!empty($serverParams['SCRIPT_NAME'])) {
                $path = $serverParams['SCRIPT_NAME'];
                if (!empty($serverParams['QUERY_STRING'])) {
                    $path .= '?' . $serverParams['QUERY_STRING'];
                }
            }
            $uri = $scheme . '://' . $host;
            if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) {
                $uri .= ':' . $port;
            }
            $uri .= $path;
            $this->uri = new Uri($uri);
        }
        return $this->uri;
    }

    public function getQueryParams(): array {
        if ($this->queryParams === null) {
            $uri = $this->connection->getRequestTarget();
            if (\str_contains($uri, '?')) {
                [$_, $queryString] = \explode("?", $uri, 2);
                \parse_str($queryString, $this->queryParams);
            } else {
                $this->queryParams = [];
            }
        }
        return $this->queryParams;
    }

    public function withQueryParams(array $query): ServerRequestInterface {
        $c = clone $this;
        $c->queryParams = $query;
        return $c;
    }

    public function getUploadedFiles(): array {
        return $this->uploadedFiles;
    }

    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface {
        $c = clone $this;
        $c->uploadedFiles = $uploadedFiles;
        return $c;
    }

    public function getParsedBody() {
        if (!$this->hasParsedBody) {
            $this->hasParsedBody = true;
            $method = \strtoupper($this->getMethod());
            if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
                if ($this->hasHeader('content-type')) {
                    $contentType = $this->getHeaderLine('content-type');
                    
                    if ($contentType === 'application/x-www-form-urlencoded') {
                        if (\function_exists('mb_parse_str')) {
                            \mb_parse_str((string) $this->getBody(), $this->parsedBody);
                        } else {
                            \parse_str((string) $this->getBody(), $this->parsedBody);
                        }
                    } elseif ($contentType === 'application/json') {
                        $result = json_decode((string) $this->getBody(), true);
                        if (\json_last_error() !== \JSON_ERROR_NONE) {
                            return $this->parsedBody = [];
                        }
                        return $this->parsedBody = $result;
                    } elseif ($contentType === 'multipart/form-data') {
                        $this->parseMultipartFormData();
                    }
                }
            }
        }
        return $this->parsedBody;
    }
    
    private function parseMultipartFormData() {
        throw new \Exception("Parsing multipart/form-data not implemented yet");
        $files = [];
        $parsedBody = [];

        $contentType = $this->getHeaderLine('content-type');
        if (\preg_match('/boundary=(.*)$/', $contentType, $matches)) {
            $boundary = $matches[1];
        } else {
            throw new \RuntimeException("Boundary not found in Content-Type header");
        }

        $buffer = new StringBuffer();
        
        $boundary = '--' . $boundary;
        $length = 65536;

    }    

    public function withParsedBody($data): ServerRequestInterface {
        $c = clone $this;
        $c->hasParsedBody = true;
        $c->parsedBody = $data;
        return $c;
    }

    public function getRequestTarget(): string {
        if ($this->requestTarget === null) {
            $this->requestTarget = $this->connection->getRequestTarget();
        }
        return $this->requestTarget;
    }

    public function withRequestTarget(string $requestTarget): RequestInterface {
        $c = clone $this;
        $c->requestTarget = $requestTarget;
        return $c;
    }

    public function getMethod(): string {
        if ($this->requestMethod === null) {
            $this->requestMethod = $this->connection->getRequestMethod();
        }
        return $this->requestMethod;
    }

    public function withMethod(string $method): RequestInterface {
        $c = clone $this;
        $c->requestMethod = $method;
        return $c;
    }
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @return mixed
     */
    public function getAttribute($attribute, $default = null)
    {
        if (!\is_string($attribute)) {
            throw new \InvalidArgumentException('Attribute name must be a string');
        }

        if (false === \array_key_exists($attribute, $this->attributes)) {
            return $default;
        }

        return $this->attributes[$attribute];
    }

    /**
     * @return static
     */
    public function withAttribute($attribute, $value): ServerRequestInterface
    {
        if (!\is_string($attribute)) {
            throw new \InvalidArgumentException('Attribute name must be a string');
        }

        $new = clone $this;
        $new->attributes[$attribute] = $value;

        return $new;
    }

    /**
     * @return static
     */
    public function withoutAttribute($attribute): ServerRequestInterface
    {
        if (!\is_string($attribute)) {
            throw new \InvalidArgumentException('Attribute name must be a string');
        }

        if (false === \array_key_exists($attribute, $this->attributes)) {
            return $this;
        }

        $new = clone $this;
        unset($new->attributes[$attribute]);

        return $new;
    }
}