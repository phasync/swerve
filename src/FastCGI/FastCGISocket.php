<?php

namespace Swerve\FastCGI;

use phasync;
use phasync\CancelledException;
use phasync\Debug;
use phasync\TimeoutException;
use phasync\Util\FastCGI\Record;
use phasync\Util\StringBuffer;
use phasync\Util\WaitGroup;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Swerve\ClosedException;
use Swerve\Connection;
use Swerve\ProtocolErrorException;
use Swerve\Util\Logger;
use Throwable;

final class FastCGISocket
{
    private \Closure $addConnectionFunction;
    private mixed $socket;
    private string $peerName;
    private StringBuffer $readBuffer;
    private StringBuffer $writeBuffer;
    private bool $keepConnection = true;
    /**
     * @var array<int,FastCGIConnection>
     */
    private array $connections = [];
    private LoggerInterface $logger;
    private bool $keepRunning = true;

    public function __construct(\Closure $addConnectionFunction, $socket, string $peerName)
    {
        $this->addConnectionFunction = $addConnectionFunction;
        $this->socket = $socket;
        $this->peerName = $peerName;
        $this->readBuffer = new StringBuffer();
        $this->writeBuffer = new StringBuffer();
        \stream_set_blocking($this->socket, false);
        $this->logger = new Logger($peerName, LogLevel::INFO);
    }

    public function __destruct() {
        $this->keepRunning = false;
    }

    public function write(Record $fcgiRecord): void
    {
        $this->writeBuffer->write($fcgiRecord->toString());
    }

    /**
     * This function should run in a coroutine and will be monitoring
     * the stream for data and writing responses back.
     */
    public function run(): void
    {
        // $this->logger->debug("Socket running");
        /**
         * Writes anything that arrives in the write buffer to the socket.
         */       
        $wg = new WaitGroup(); 
        $writer = phasync::go(function() use (&$reader, $wg) {
            try {
                $wg->add();
                while ($this->keepRunning && \is_resource($this->socket)) {
                    $chunk = \fread(\phasync::readable($this->socket, \PHP_FLOAT_MAX), 131072);
                    if ($chunk === false) {
                        return;
                    }
                    //$this->logger->info('Read {bytes} bytes from socket', ['bytes' => \strlen($chunk)]);
                    if ($chunk !== '') {
                        $this->readBuffer->write($chunk);
                        $this->dispatch();    
                    }
                }
            }
            catch (CancelledException) {}
            finally {
                $wg->done();
                //echo "Stopping writer\n";
                if (!$reader->isTerminated()) {
                    phasync::cancel($reader);
                }
                $this->keepRunning = false;
            }
        });
        $reader = phasync::go(function() use ($writer, $wg) {
            try {
                $wg->add();
                while ($this->keepRunning) {
                    try {
                        $chunk = $this->writeBuffer->read(131072, true);
                    } catch (TimeoutException) {
                        continue;
                    }
                    $result = \fwrite(phasync::writable($this->socket, \PHP_FLOAT_MAX), $chunk);
                    if ($result === false) {
                        return;
                    }
                    if ($result < \strlen($chunk)) {
                        $this->writeBuffer->unread(\substr($chunk, $result));
                    }
                }
            }
            catch (CancelledException) {}
            finally {
                $wg->done();
                //echo "Stopping reader\n";
                if (!$writer->isTerminated()) {
                    phasync::cancel($writer);
                }
                $this->keepRunning = false;
            }
        });

        $wg->await();
        try {
            phasync::await($reader, \PHP_FLOAT_MAX);
        }
        catch (Throwable $e) {
            $this->logger->error($e);
        }    
        try {
            phasync::await($writer, \PHP_FLOAT_MAX);
        }
        catch (Throwable $e) {
            $this->logger->error($e);
        }

        if (\is_resource($this->socket)) {
            \fclose($this->socket);
        }
    }

    public function endConnection(FastCGIConnection $connection): void {
        if (!$this->keepConnection) {
            $this->writeBuffer->end();
            if (\is_resource($this->socket)) {
                //echo "ENDING CONNECTION 1\n";
                \fclose($this->socket);
            }
        }
        unset($this->connections[$connection->requestId]);
    }

    /**
     * Forward all full FCGI records in the read buffer as possible to
     * connections.
     * 
     * @throws Throwable 
     */
    private function dispatch(): void
    {
        try {
            while (null !== ($record = Record::parse($this->readBuffer))) {                                
                if ($record->requestId === 0) {
                    // Management record (FCGI_GET_VALUES, FCGI_GET_VALUES_RESULT)
                    if ($record->type === Record::FCGI_GET_VALUES) {
                        $record->setGetValuesResult([
                            'FCGI_MAX_REQS' => '10000',
                            'FCGI_MPXS_CONNS' => '1',
                        ]);
                        $this->writeBuffer->write($record->toString());
                    } else {
                        $record->setUnknownType($record->type);
                        $this->writeBuffer->write($record->toString());
                    }
                } elseif ($record->type === Record::FCGI_BEGIN_REQUEST) {
                    //echo "Request Count for Socket: " . count($this->connections) . "\n";
                    // Start a new request
                    if (isset($this->connections[$record->requestId])) {
                        throw new ProtocolErrorException('FCGI_BEGIN_REQUEST with existing request id '.$record->requestId);
                    }
                    $record->getBeginRequest($role, $flags);
                    if (!($flags & Record::FCGI_KEEP_CONN)) {
                        $this->keepConnection = false;
                    }
                    $request = new FastCGIConnection($this, $record->requestId);
                    $requestTime = \microtime(true);
                    $request->serverParams += [
                        'SERVER_SOFTWARE' => 'Swerve',
                        'REQUEST_TIME' => (int) $requestTime,
                        'REQUEST_TIME_FLOAT' => $requestTime,
                    ];

                    $this->connections[$record->requestId] = $request;
                    $record->returnToPool();
                    ($this->addConnectionFunction)($request);
                    continue;
                } elseif (!isset($this->connections[$record->requestId])) {
                    // We don't recognize the request id
                    if ($record->type === Record::FCGI_STDIN && $record->content === '') {
                        // The request may already have been responded to
                        continue;
                    }
                    throw new ProtocolErrorException(Record::TYPES[$record->type].' with unknown request id '.$record->requestId);
                } elseif ($record->type === Record::FCGI_STDIN) {
                    $connection = $this->connections[$record->requestId];
                    if ($record->content === '') {
                        $connection->stdin->end();
                    } else {
                        $connection->stdin->write($record->content);
                    }
                } elseif ($record->type === Record::FCGI_ABORT_REQUEST) {
                    // Abort a request (client disconnected etc)
                    $this->connections[$record->requestId]->setAborted();
                } elseif ($record->type === Record::FCGI_PARAMS) {
                    // FastCGI params
                    $connection = $this->connections[$record->requestId];

                    if ($record->content === '') {
                        // No more params will be received

                        if (empty($connection->serverParams['REQUEST_METHOD'])) {
                            throw new ProtocolErrorException('FCGI_PARAMS MUST contain REQUEST_METHOD');
                        } else {
                            $connection->setRequestMethod($connection->serverParams['REQUEST_METHOD']);
                        }

                        if (isset($connection->serverParams['REQUEST_URI'])) {
                            // Server provided us with a full request URI
                            $connection->setRequestTarget($connection->serverParams['REQUEST_URI']);
                        } else {
                            // Must build REQUEST_URI from other params
                            $requestUri = $connection->serverParams['SCRIPT_NAME']
                                .($connection->serverParams['PATH_INFO'] ?? '')
                                .(!empty($connection->serverParams['QUERY_STRING']) ? '?'.$connection->serverParams['QUERY_STRING'] : '');
                            $connection->setRequestTarget($requestUri);
                        }

                        if (isset($connection->serverParams['SERVER_PROTOCOL'])) {
                            [$_, $protocolVersion] = \explode('/', $connection->serverParams['SERVER_PROTOCOL'], 2);
                            $connection->setProtocolVersion($protocolVersion);
                        } else {
                            $connection->setProtocolVersion('1.0');
                        }

                        // Signal we may begin responding
                        $connection->setState(Connection::STATE_RESPONSE_HEAD);
                    } else {
                        foreach ($record->getParams() as $key => $value) {
                            if (\str_starts_with($key, 'HTTP_')) {
                                $headerName = \strtolower(\str_replace('_', '-', \substr($key, 5)));
                                $connection->addRequestHeader($headerName, $value);
                            } else {
                                $connection->serverParams[$key] = $value;
                            }
                        }
                    }
                    $record->returnToPool();
                } else {
                    throw new ProtocolErrorException('Unknown FCGI record type '.$record->type);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error($e);
            throw $e;
        }
    }
}
