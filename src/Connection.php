<?php

namespace Swerve;

use Closure;
use LogicException;
use phasync;
use phasync\TimeoutException;
use phasync\Util\StringBuffer;
use RuntimeException;
use Throwable;

abstract class Connection implements ConnectionInterface
{

    /**
     * Connection is not ready, headers being received
     */
    public const STATE_REQUEST_HEAD = 1;
    /**
     * Response head not sent
     */
    public const STATE_RESPONSE_HEAD = 2;
    /**
     * Request and response body communication
     */
    public const STATE_BODY = 3;
    /**
     * Connection closed
     */
    public const STATE_ENDED = 4;

    /**
     * The current state of the connection
     * 
     * @var int
     */
    private int $state = self::STATE_REQUEST_HEAD;

    /**
     * The client IP address
     * 
     * @var string
     */
    private string $clientIp;

    /**
     * The client port number
     * 
     * @var string
     */
    private string $clientPort;

    /**
     * The HTTP protocol version (1.1, 2.0 etc)
     * 
     * @var string
     */
    private string $protocolVersion;

    /**
     * The request URI (typically '/')
     * 
     * @var string
     */
    private string $requestTarget;

    /**
     * The request method (GET, POST etc)
     * 
     * @var string
     */
    private string $requestMethod;

    /**
     * Request headers (['Connection: keep-alive', ...])
     * 
     * @var array
     */
    private array $requestHeaders = [];

    /**
     * Is client aborted/disconnected?
     * 
     * @var bool
     */
    private bool $aborted = false;

    public function __construct()
    {
    }

    abstract public function sendHead(array $headers, int $statusCode = 200, string $reasonPhrase = ''): void;

    abstract public function read(int $maxLength): string;

    abstract public function eof(): bool;

    abstract public function write(string $chunk): void;

    abstract public function end(): void;

    public function setState(int $state): void
    {
        assert($state === $this->state + 1, "Expecting state " . ($this->state + 1));
        $this->state = $state;
        phasync::raiseFlag($this);
    }

    public function getState(): int {
        return $this->state;
    }

    public function setRequestMethod(string $method): void
    {
        assert($this->state === self::STATE_REQUEST_HEAD, "Only settable in STATE_REQUEST_HEAD");
        $this->requestMethod = $method;
    }

    public function getRequestMethod(): string
    {
        $this->afterState(self::STATE_REQUEST_HEAD);
        return $this->requestMethod;
    }

    public function setProtocolVersion(string $protocolVersion): void
    {
        assert($this->state === self::STATE_REQUEST_HEAD, "Only settable in STATE_REQUEST_HEAD");
        $this->protocolVersion = $protocolVersion;
    }

    public function getProtocolVersion(): string
    {
        $this->afterState(self::STATE_REQUEST_HEAD);
        return $this->protocolVersion;
    }

    public function setClientIp(string $clientIp): void
    {
        $this->clientIp = $clientIp;
    }

    public function getClientIp(): string
    {
        return $this->clientIp;
    }

    public function setClientPort(int $clientPort): void
    {
        $this->clientPort = $clientPort;
    }

    public function getClientPort(): string
    {
        return $this->clientPort;
    }

    public function setRequestTarget(string $uri): void
    {
        assert($this->state === self::STATE_REQUEST_HEAD, "Only setable in STATE_REQUEST_HEAD");
        $this->requestTarget = $uri;
    }

    public function getRequestTarget(): string
    {
        $this->afterState(self::STATE_REQUEST_HEAD);
        return $this->requestTarget;
    }

    public function setRequestHeaders(array $requestHeaders): void
    {
        assert($this->state === self::STATE_REQUEST_HEAD, "Only settable in STATE_REQUEST_HEAD");
        $this->requestHeaders = $requestHeaders;
    }

    public function addRequestHeader(string $headerName, string $value): void
    {
        assert($this->state === self::STATE_REQUEST_HEAD, "Only settable in STATE_REQUEST_HEAD");
        $this->requestHeaders[$headerName][] = $value;
    }

    public function getRequestHeaders(): array
    {
        $this->afterState(self::STATE_REQUEST_HEAD);
        return $this->requestHeaders;
    }

    public function setAborted(): void
    {
        $this->aborted = true;
    }

    public function isAborted(): bool
    {
        return $this->aborted;
    }

    /**
     * Block coroutine until after a state has completed
     * 
     * @param int $state 
     * @throws TimeoutException 
     * @throws Throwable 
     */
    protected function afterState(int $state): void
    {
        while ($this->state <= $state && $this->state !== self::STATE_ENDED) {
            phasync::awaitFlag($this);
        }
    }

    /**
     * Require that the current state is ...
     * 
     * @param int $state 
     * @param string $message 
     * @throws RuntimeException 
     */
    protected function assertStateIs(int $state, string $message): void
    {
        if ($this->state !== $state) {
            throw new RuntimeException($message);
        }
    }
}
