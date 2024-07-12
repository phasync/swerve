<?php
namespace Swerve\FastCGI;

use phasync\Internal\ObjectPoolInterface;
use phasync\Internal\ObjectPoolTrait;
use phasync\Util\FastCGI\Record;
use phasync\Util\StringBuffer;
use Swerve\ConnectionInterface;

final class OldFastCGIRequest implements ConnectionInterface, ObjectPoolInterface {
    use ObjectPoolTrait;

    /**
     * Expecting params
     */
    public const STATE_PARAMS = 1;
    /**
     * Expecting request body
     */
    public const STATE_BODY = 2;

    private int $state = self::STATE_PARAMS;
    private int $requestId;
    private int $role;
    private int $flags;
    private ?Server $protocol;
    private ?StringBuffer $write;
    private ?StringBuffer $read;
    private bool $aborted;
    private array $params = [];

    /**
     * Create a new FastCGIConnection and attach the response string buffer where
     * records should be written.
     * 
     * @param Server $fcgiSocket 
     * @param StringBuffer $write The buffer where FastCGI records are written to
     * @param int $requestId 
     * @param int $role 
     * @param int $flags 
     * @return OldFastCGIRequest 
     */
    public static function create(FastCGISocket $fcgiSocket, int $requestId, int $role, int $flags): OldFastCGIRequest {
        $instance = self::popInstance() ?? new OldFastCGIRequest();
        $instance->protocol = $fcgiSocket;
        $instance->requestId = $requestId;
        $instance->role = $role;
        $instance->flags = $flags;
        $instance->read = new StringBuffer();
        $instance->aborted = false;
        return $instance;
    }

    private function __construct() {}

    /**
     * Handle a received FastCGI record routed to this request id.
     * 
     * @param Record $record 
     */
    public function handleFastCGIRecord(Record $record): void {
        switch ($record->type) {
            case Record::FCGI_ABORT_REQUEST:
                /**
                 * When the request is aborted, there should be no further communication
                 * from this connection. We'll just send the FCGI_END_REQUEST.
                 */
                $record->setAbortRequest();
                $this->write->write($record->toString());

                // By not having the reference, it is impossible to write back
                $this->write = null;

                // Enable the request handler to detect client disconnect/abort
                $this->aborted = true;

                break;
            case Record::FCGI_PARAMS:
                /**
                 * Accumulate headers until an empty FCGI_PARAMS
                 */
                if ($this->state !== self::STATE_PARAMS) {
                    \trigger_error("Not expecting FCGI_PARAMS after initial params\n");
                } elseif ($record->content !== '') {
                    foreach ($record->getParams() as $key => $value) {
                        $this->params[$key] = $value;
                    }
                } else {
                    $this->state = self::STATE_BODY;
                }
                break;
            case Record::FCGI_STDIN:
                if ($record->content === '') {
                    /**
                     * STDIN end of file received
                     */
                    $this->read->end();
                } else {
                    /**
                     * STDIN data received
                     */
                    $this->read->write($record->content);
                }
                break;
            case Record::FCGI_DATA:
                /**
                 * We don't support FCGI_DATA packets currently.
                 */
                trigger_error("Unsupported " . Record::TYPES[$record->type] . " received from web server");
                break;
            case Record::FCGI_STDERR:
            case Record::FCGI_STDOUT:
            case Record::FCGI_END_REQUEST:
            default:
                /**
                 * These record types should not be sent by the web server
                 */
                \trigger_error("Unexpected " . Record::TYPES[$record->type] . " received from web server");
                break;
        }
    }

    public function returnToPool(): void {
        $this->protocol = null;
        $this->write = null;
        $this->read = null;
        self::$pool[self::$instanceCount++] = $this;
    }

    public function isAborted(): bool {
        return $this->aborted;
    }

    public function getProtocolVersion(): string {

    }

    public function getClientIp(): string { }

    public function getClientPort(): string { }

    public function getRequestMethod(): string { }

    public function getRequestHeaders(): array { }

    public function getRequestTarget(): string { }

    public function sendStatus(int $statusCode, ?string $reasonPhrase = ''): void { }

    public function sendHeaders(array $headers): void { }

    public function read(int $maxLength): string { }

    public function eof(): bool { }

    public function write(string $chunk): void { }

    public function end(): void { }
}