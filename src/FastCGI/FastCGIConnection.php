<?php

namespace Swerve\FastCGI;

use phasync\Internal\ObjectPoolInterface;
use phasync\Internal\ObjectPoolTrait;
use phasync\Util\FastCGI\Record;
use phasync\Util\StringBuffer;
use Swerve\Connection;

final class FastCGIConnection extends Connection
{

    /**
     * Used for caching information for the FastCGISocket.
     */
    public array $serverParams = [];

    private FastCGISocket $fcgiSocket;
    public StringBuffer $stdin;
    private Record $fcgiRecord;
    public readonly int $requestId;

    public function __construct(FastCGISocket $fcgiSocket, int $requestId) {
        parent::__construct();
        $this->requestId = $requestId;
        $this->fcgiSocket = $fcgiSocket;
        $this->fcgiRecord = Record::create();
        $this->fcgiRecord->requestId = $requestId;
        $this->fcgiSocket = $fcgiSocket;
        $this->stdin = new StringBuffer();
    }

    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    public function sendHead(array $headers, int $statusCode = 200, string $reasonPhrase = ''): void
    {
        $this->assertStateIs(self::STATE_RESPONSE_HEAD, "Can't send response head at this time (state is " . $this->getState() . ")");

        $this->fcgiRecord->setStdout(
            "Status: $statusCode".($reasonPhrase !== '' ? ' '.$reasonPhrase : '')."\r\n".
                \implode("\r\n", $headers)."\r\n\r\n"
        );
        $this->fcgiSocket->write($this->fcgiRecord);
        $this->setState(self::STATE_BODY);
    }

    public function write(string $chunk): void
    {
        $this->assertStateIs(self::STATE_BODY, "Can't write");

        $this->fcgiRecord->setStdout($chunk);
        $this->fcgiSocket->write($this->fcgiRecord);
    }

    public function end(): void
    {
        $this->assertStateIs(self::STATE_BODY, "Can't write");

        $this->fcgiRecord->setStdout('');
        $this->fcgiSocket->write($this->fcgiRecord);
        $this->fcgiRecord->setEndRequest();
        $this->fcgiSocket->write($this->fcgiRecord);
        $this->setState(self::STATE_ENDED);
        $this->fcgiSocket->endConnection($this);
    }

    public function writeError(string $chunk): void
    {
        $this->fcgiRecord->setStderr($chunk);
        $this->fcgiSocket->write($this->fcgiRecord);
    }

    public function read(int $maxLength): string
    {
        return $this->stdin->read($maxLength, true);
    }

    public function eof(): bool
    {
        return $this->stdin->eof();
    }
}
