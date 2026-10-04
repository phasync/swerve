<?php

namespace Swerve\FastCGI;

use phasync\Util\StringBuffer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Swerve\StreamingResponderInterface;

/**
 * One request on a FastCGI connection, from FCGI_BEGIN_REQUEST until its response is written:
 * collects what FCGI_PARAMS and FCGI_STDIN bring, and then sends the response as FCGI_STDOUT.
 *
 * @internal
 */
final class FastCGIRequest implements StreamingResponderInterface
{
    /** The server params: everything but the HTTP_ ones, which are the request headers. */
    public array $params;

    /** @var array<string, string[]> lower-case name => values */
    public array $headers = [];

    /** @var array<string, string> lower-case name => name: FastCGI has lost the case */
    public array $headerNames = [];

    public readonly StringBuffer $stdin;
    private readonly Record $record;
    private bool $headSent = false;
    private bool $ended = false;

    public function __construct(
        private readonly FastCGISocket $socket,
        public readonly int $requestId,
        private readonly LoggerInterface $logger,
    ) {
        $time = \microtime(true);
        $this->params = [
            'SERVER_SOFTWARE'    => 'Swerve',
            'REQUEST_TIME'       => (int) $time,
            'REQUEST_TIME_FLOAT' => $time,
        ];
        $this->record            = Record::create();
        $this->record->requestId = $requestId;
        $this->stdin             = new StringBuffer();
    }

    /**
     * Send the application's response. A 101 is a protocol upgrade, which no
     * front server carries over FastCGI: it is answered with 501.
     */
    public function respond(ServerRequestInterface $request, ResponseInterface $response): mixed
    {
        $this->send($request, $response);
        $this->end(); // the front server has the response now, while the request's own work may go on

        return null;
    }

    private function send(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status < 200) {
            if (101 !== $status) {
                throw new \UnexpectedValueException("A final response can't have status $status");
            }
            $this->logger->warning('{request}: a protocol upgrade needs HTTP mode, no web server carries it over FastCGI', ['request' => $request->getMethod() . ' ' . $request->getRequestTarget()]);
            $response->getBody()->close();
            $this->sendHead(501, 'Not Implemented', ['Content-Type: text/plain']);
            $this->write('Protocol upgrades need swerve in HTTP mode');

            return;
        }
        $lines     = [];
        $hasLength = $hasType = false;
        foreach ($response->getHeaders() as $name => $values) {
            $lower = \strtolower($name);
            if ('content-length' === $lower) {
                $hasLength = true;
            } elseif ('content-type' === $lower) {
                $hasType = true;
            }
            foreach ($values as $value) {
                $lines[] = "$name: $value";
            }
        }
        $body = $response->getBody();
        if (!$hasLength && null !== ($size = $body->getSize())) {
            $lines[] = "Content-Length: $size";
        }
        if (!$hasType) {
            $lines[] = 'Content-Type: text/html; charset=utf-8';
        }
        $this->sendHead($status, $response->getReasonPhrase(), $lines);
        try {
            $body->rewind();
        } catch (\RuntimeException) {
        }
        while (!$body->eof()) {
            $chunk = $body->read(65536);
            if ('' !== $chunk) {
                $this->write($chunk); // an empty FCGI_STDOUT record would end the stream
            }
        }
    }

    public function streamHead(int $status, string $reason, array $headers): void
    {
        $lines = [];
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $lines[] = "$name: $value";
            }
        }
        $this->sendHead($status, $reason, $lines);
    }

    public function stream(string $data): bool
    {
        if ('' !== $data) { // an empty FCGI_STDOUT record would end the stream
            $this->write($data);
        }

        return true;
    }

    public function streamGone(): bool
    {
        return false;
    }

    public function streamEnd(): mixed
    {
        $this->end();

        return null;
    }

    /**
     * The application failed: a 500 if nothing was sent yet.
     */
    public function fail(): void
    {
        if (!$this->headSent) {
            $this->sendHead(500, 'Internal Server Error', ['Content-Type: text/plain']);
            $this->write('Internal Server Error');
        }
    }

    /**
     * End the request, so that the front server is never left waiting for it.
     */
    public function end(): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        $this->record->setStdout('');
        $this->socket->write($this->record);
        $this->record->setEndRequest();
        $this->socket->write($this->record);
        $this->socket->requestEnded($this);
    }

    /**
     * @param string[] $lines
     */
    private function sendHead(int $status, string $reason, array $lines): void
    {
        $this->headSent = true;
        $this->write("Status: $status" . ('' !== $reason ? " $reason" : '') . "\r\n" . \implode("\r\n", $lines) . "\r\n\r\n");
    }

    private function write(string $chunk): void
    {
        $this->record->setStdout($chunk);
        $this->socket->write($this->record);
    }
}
