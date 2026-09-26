<?php

namespace Swerve\FastCGI;

use phasync\CancelledException;
use phasync\Debug;
use phasync\IOException;
use phasync\TimeoutException;
use phasync\Util\StringBuffer;
use Psr\Log\LoggerInterface;
use Swerve\Connection;
use Swerve\ProtocolErrorException;
use Swerve\Util\Logger;

final class FastCGISocket
{
    private \Closure $addConnectionFunction;
    private mixed $socket;
    private string $peerName;
    private StringBuffer $readBuffer;
    private StringBuffer $writeBuffer;
    private bool $keepConnection = true;
    /** The write buffer was ended: the socket closes once it is written, and answers nothing more. */
    private bool $ended = false;
    /**
     * @var array<int,FastCGIConnection>
     */
    private array $connections = [];
    private LoggerInterface $logger;
    private bool $keepRunning = true;

    public function __construct(\Closure $addConnectionFunction, $socket, string $peerName, LoggerInterface $logger)
    {
        $this->addConnectionFunction = $addConnectionFunction;
        $this->socket = $socket;
        $this->peerName = $peerName;
        $this->readBuffer = new StringBuffer();
        $this->writeBuffer = new StringBuffer();
        \stream_set_blocking($this->socket, false);
        $this->logger = $logger;
    }

    public function __destruct()
    {
        $this->keepRunning = false;
    }

    public function write(Record $record): void
    {
        // fwrite(STDERR, '> '.Record::TYPES[$record->type].' requestId='.$record->requestId."\n");
        $this->writeBuffer->write($record->toString());
        // if ($record->type === Record::FCGI_STDOUT) {
        // fwrite(STDERR, $record->content."\n");
        // }
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
        $writer = \phasync::go(function () use (&$reader) {
            try {
                while ($this->keepRunning && \is_resource($this->socket)) {
                    $chunk = \fread(\phasync::readable($this->socket, \PHP_FLOAT_MAX), 131072);
                    if ($chunk === false || ($chunk === '' && \feof($this->socket))) {
                        // The front server closed the connection, or the writer below shut it down
                        return;
                    }
                    // $this->logger->info('Read {bytes} bytes from socket', ['bytes' => \strlen($chunk)]);
                    if ($chunk !== '') {
                        $this->readBuffer->write($chunk);
                        $this->dispatch();
                        if (!$this->keepConnection && $this->idle()) {
                            $this->end(); // draining: what was in flight when it started is done
                        }
                    }
                }
            } catch (IOException) {
            } catch (CancelledException) {
            } finally {
                // Nothing more can be sent either; the responses of requests still running are
                // dropped. Not while PHP shuts down, see FastCGIServer::$exiting.
                if (null !== $reader && !$reader->isTerminated() && !FastCGIServer::$exiting) {
                    \phasync::cancel($reader);
                }
                if (\is_resource($this->socket)) {
                    \fclose($this->socket);
                }
                $this->keepRunning = false;
            }
        });
        $reader = \phasync::go(function () {
            try {
                while ($this->keepRunning) {
                    $chunk = $this->writeBuffer->read(131072);
                    if ('' === $chunk) {
                        // Ended by endConnection() without FCGI_KEEP_CONN, and all of it written
                        return;
                    }
                    $result = \fwrite(\phasync::writable($this->socket, \PHP_FLOAT_MAX), $chunk);
                    if ($result === false) {
                        return;
                    }
                    if ($result < \strlen($chunk)) {
                        try {
                            $this->writeBuffer->unread(\substr($chunk, $result));
                        } catch (\LogicException) {
                        }
                    }
                }
            } catch (IOException) {
            } catch (CancelledException) {
            } finally {
                // Not fclose(): the coroutine above waits on the socket. The shutdown wakes it
                // with the end of the stream, and it closes the socket.
                if (\is_resource($this->socket)) {
                    @\stream_socket_shutdown($this->socket, \STREAM_SHUT_RDWR);
                }
                $this->keepRunning = false;
            }
        });

        try {
            \phasync::await($reader, \PHP_FLOAT_MAX);
        } catch (\Throwable $e) {
            $this->logger->error('FastCGI connection from {peer} failed: {exception}', ['peer' => $this->peerName, 'exception' => $e]);
        }
        try {
            \phasync::await($writer, \PHP_FLOAT_MAX);
        } catch (\Throwable $e) {
            $this->logger->error('FastCGI connection from {peer} failed: {exception}', ['peer' => $this->peerName, 'exception' => $e]);
        }

        if (\is_resource($this->socket)) {
            \fclose($this->socket);
        }
    }

    /**
     * A request ended. Without FCGI_KEEP_CONN, or while draining, the socket closes once its
     * last request ended and all of it is written; not after the first of several
     * multiplexed ones.
     */
    public function endConnection(FastCGIConnection $connection): void
    {
        unset($this->connections[$connection->requestId]);
        if (!$this->keepConnection && $this->idle()) {
            $this->end();
        }
    }

    /**
     * Close the socket once its requests in flight are answered, see FastCGIServer::drain().
     * A request the front server sent already counts as in flight, also when it is still in
     * the kernel's buffer: the reader dispatches it first, and ends the socket after.
     */
    public function drain(): void
    {
        $this->keepConnection = false;
        $read                 = [$this->socket];
        $write                = $except = null;
        // Closed already when the front server closed it, but run() has not returned yet
        if ($this->idle() && (!\is_resource($this->socket) || !@\stream_select($read, $write, $except, 0))) {
            $this->end();
        }
    }

    /** No request is open, nor partly received. */
    private function idle(): bool
    {
        return !$this->connections && $this->readBuffer->isEmpty();
    }

    private function end(): void
    {
        if (!$this->ended) {
            $this->ended = true;
            $this->writeBuffer->end();
        }
    }

    /**
     * Forward all full FCGI records in the read buffer as possible to
     * connections.
     *
     * @throws \Throwable logged by run()
     */
    private function dispatch(): void
    {
        if ($this->ended) {
            return; // sent after the socket was done: nothing can be answered any more
        }
        while (null !== ($record = Record::parse($this->readBuffer))) {
            // fwrite(STDERR, '< '.Record::TYPES[$record->type].' requestId='.$record->requestId."\n");

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
                // echo "Request Count for Socket: " . count($this->connections) . "\n";
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
                throw new ProtocolErrorException((Record::TYPES[$record->type] ?? 'Record type '.$record->type).' with unknown request id '.$record->requestId);
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
    }
}
