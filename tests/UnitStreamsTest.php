<?php

/*
 * Pure unit tests, no server and no sockets: FastCGI records, the UpgradeStream that carries an
 * upgraded connection's output, and the ProtocolUpgrade response around it.
 */

use phasync\Psr\Response;
use phasync\Psr\ServerRequest;
use phasync\Psr\StreamFactory;
use phasync\TimeoutException;
use phasync\Util\StringBuffer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\FastCGI\Record;
use Swerve\Http\ProtocolUpgrade;
use Swerve\Http\UpgradeStream;

/**
 * A ProtocolUpgrade that exposes its protected API; a request with a "refuse" header is refused.
 */
class UpgradeProbe extends ProtocolUpgrade
{
    public const WRITE_TIMEOUT = 0.05;

    protected function handshake(ServerRequestInterface $request): array|ResponseInterface
    {
        if ($request->hasHeader('X-Refuse')) {
            return new Response(403, [], StreamFactory::create('no'));
        }

        return ['Upgrade' => 'probe'];
    }

    public function read(int $length): string
    {
        return parent::read($length);
    }

    public function write(string $bytes): bool
    {
        return parent::write($bytes);
    }

    public function writeNow(string $bytes): bool
    {
        return parent::writeNow($bytes);
    }

    public function end(): void
    {
        parent::end();
    }
}

/** A record of $type for request 1 with $content, as the wire bytes. */
function record_bytes(int $type, string $content, int $requestId = 1): string
{
    $record            = Record::create();
    $record->type      = $type;
    $record->requestId = $requestId;
    $record->content   = $content;

    return $record->toString();
}

/** Parse $bytes as one record from a fresh buffer. */
function record_parse(string $bytes): ?Record
{
    $buffer = new StringBuffer();
    $buffer->write($bytes);

    return Record::parse($buffer);
}

// ---------------------------------------------------------------------------------------------
// FastCGI Record
// ---------------------------------------------------------------------------------------------

test('a STDOUT record round trips through toString() and parse()', function () {
    $record            = Record::create();
    $record->requestId = 7;
    $record->setStdout('hello');
    $bytes = $record->toString();

    // version 1, type 6, request 7, 5 bytes of content, 3 of padding to a multiple of 8
    expect($bytes)->toBe("\x01\x06\x00\x07\x00\x05\x03\x00" . "hello\0\0\0");
    $parsed = record_parse($bytes);
    expect([$parsed->version, $parsed->type, $parsed->requestId])->toBe([1, Record::FCGI_STDOUT, 7]);
    expect($parsed->getStdout())->toBe('hello');
});

test('content that is a multiple of 8 bytes gets no padding', function () {
    expect(strlen(record_bytes(Record::FCGI_STDOUT, '12345678')))->toBe(16);
    expect(strlen(record_bytes(Record::FCGI_STDOUT, '1234567')))->toBe(16);
});

test('an empty STDOUT record is a bare 8 byte header', function () {
    $record            = Record::create();
    $record->requestId = 3;
    $record->setStdout('');
    expect($record->toString())->toBe("\x01\x06\x00\x03\x00\x00\x00\x00");
    $parsed = record_parse($record->toString());
    expect([$parsed->type, $parsed->requestId, $parsed->content])->toBe([Record::FCGI_STDOUT, 3, '']);
});

test('STDIN and STDERR records carry their content', function () {
    $record = Record::create();
    $record->setStdin('in');
    expect($record->type)->toBe(Record::FCGI_STDIN);
    expect($record->getStdin())->toBe('in');
    $record->setStderr('err');
    expect($record->type)->toBe(Record::FCGI_STDERR);
    expect($record->getStderr())->toBe('err');
});

test('an END_REQUEST record round trips its status and protocol status', function () {
    $record            = Record::create();
    $record->requestId = 2;
    $record->setEndRequest(258, Record::FCGI_PROT_STATUS_OVERLOADED);
    $parsed = record_parse($record->toString());
    $parsed->getEndRequest($appStatus, $protocolStatus);

    expect($parsed->type)->toBe(Record::FCGI_END_REQUEST);
    expect([$appStatus, $protocolStatus])->toBe([258, Record::FCGI_PROT_STATUS_OVERLOADED]);
    expect(strlen($parsed->content))->toBe(8);
});

test('setEndRequest() defaults to status 0 and REQUEST_COMPLETE', function () {
    $record = Record::create();
    $record->setEndRequest();
    $record->getEndRequest($appStatus, $protocolStatus);
    expect([$appStatus, $protocolStatus])->toBe([0, Record::FCGI_PROT_STATUS_REQUEST_COMPLETE]);
});

test('a BEGIN_REQUEST record round trips its role and flags', function () {
    $record            = Record::create();
    $record->requestId = 9;
    $record->setBeginRequest(Record::FCGI_ROLE_AUTHORIZER, Record::FCGI_KEEP_CONN);
    $parsed = record_parse($record->toString());
    $parsed->getBeginRequest($role, $flags);

    expect($parsed->type)->toBe(Record::FCGI_BEGIN_REQUEST);
    expect([$role, $flags])->toBe([Record::FCGI_ROLE_AUTHORIZER, Record::FCGI_KEEP_CONN]);

    $record->setBeginRequest();
    $record->getBeginRequest($role, $flags);
    expect([$role, $flags])->toBe([Record::FCGI_ROLE_RESPONDER, 0]);
});

test('an ABORT_REQUEST record has no content', function () {
    $record = Record::create();
    $record->setStdout('left over');
    $record->setAbortRequest();
    expect([$record->type, $record->content])->toBe([Record::FCGI_ABORT_REQUEST, '']);
});

test('params with short names and values are encoded with one byte lengths', function () {
    $record = Record::create();
    $record->setParams(['A' => 'bc', '' => '']);

    expect($record->content)->toBe("\x01\x02Abc" . "\x00\x00");
    expect($record->type)->toBe(Record::FCGI_PARAMS);
    expect($record->getParams())->toBe(['A' => 'bc', '' => '']);
});

test('params with names or values over 127 bytes use four byte lengths', function () {
    $name  = str_repeat('N', 128);
    $value = str_repeat('v', 300);
    $record = Record::create();
    $record->requestId = 1;
    $record->setParams([$name => 'x', 'short' => $value, 'edge' => str_repeat('e', 127)]);

    // The high bit of the first length byte marks the four byte form
    expect(substr($record->content, 0, 6))->toBe("\x80\x00\x00\x80\x01" . 'N');
    expect(strpos($record->content, "\x05\x80\x00\x01\x2Cshort"))->not->toBeFalse();
    expect(strpos($record->content, "\x04\x7Fedge"))->not->toBeFalse();

    $parsed = record_parse($record->toString())->getParams();
    expect($parsed)->toBe([$name => 'x', 'short' => $value, 'edge' => str_repeat('e', 127)]);
});

test('params keep their order and binary values', function () {
    $params = ['REQUEST_METHOD' => 'GET', 'HTTP_X_BIN' => "\0\xFF\x80", 'QUERY_STRING' => ''];
    $record = Record::create();
    $record->setParams($params);
    expect($record->getParams())->toBe($params);
    expect(array_keys($record->getParams()))->toBe(array_keys($params));
});

test('GET_VALUES carries only names, GET_VALUES_RESULT names and values', function () {
    $record = Record::create();
    $record->requestId = 0;
    $record->setGetValues(['FCGI_MAX_CONNS', 'FCGI_MPXS_CONNS']);
    expect($record->type)->toBe(Record::FCGI_GET_VALUES);
    expect($record->getGetValues())->toBe(['FCGI_MAX_CONNS', 'FCGI_MPXS_CONNS']);

    $record->setGetValuesResult(['FCGI_MAX_CONNS' => '100', 'FCGI_MPXS_CONNS' => '1']);
    expect($record->type)->toBe(Record::FCGI_GET_VALUES_RESULT);
    $parsed = record_parse($record->toString());
    expect($parsed->getGetValuesResult())->toBe(['FCGI_MAX_CONNS' => '100', 'FCGI_MPXS_CONNS' => '1']);
});

test('an UNKNOWN_TYPE record names the type it did not understand', function () {
    $record            = Record::create();
    $record->requestId = 0;
    $record->setUnknownType(42);

    expect($record->type)->toBe(Record::FCGI_UNKNOWN_TYPE);
    expect($record->content)->toBe("\x2A\0\0\0\0\0\0\0");
    expect(record_parse($record->toString())->content)->toBe("\x2A\0\0\0\0\0\0\0");
});

test('content of exactly 65535 bytes is one record', function () {
    $content = str_repeat('a', 65535);
    $bytes   = record_bytes(Record::FCGI_STDOUT, $content);

    expect(strlen($bytes))->toBe(8 + 65535 + 1);
    expect(record_parse($bytes)->content)->toBe($content);
});

test('content over 65535 bytes is split into records of 65528 bytes', function () {
    $content = random_bytes(65528 * 2 + 100);
    $bytes   = record_bytes(Record::FCGI_STDOUT, $content, 5);

    $buffer = new StringBuffer();
    $buffer->write($bytes);
    $lengths = [];
    $joined  = '';
    while (null !== ($record = Record::parse($buffer))) {
        expect([$record->type, $record->requestId])->toBe([Record::FCGI_STDOUT, 5]);
        $lengths[] = strlen($record->content);
        $joined .= $record->content;
    }
    expect($lengths)->toBe([65528, 65528, 100]);
    expect($joined)->toBe($content);
    expect(strlen($bytes))->toBe(3 * 8 + 65528 * 2 + 104);
});

test('content of exactly twice 65528 bytes is two records with no empty one after', function () {
    $bytes  = record_bytes(Record::FCGI_STDOUT, str_repeat('x', 65528 * 2));
    $buffer = new StringBuffer();
    $buffer->write($bytes);
    $count = 0;
    while (null !== Record::parse($buffer)) {
        ++$count;
    }
    expect($count)->toBe(2);
});

test('parse() returns null until the whole header has arrived', function () {
    $bytes  = record_bytes(Record::FCGI_STDOUT, 'hello');
    $buffer = new StringBuffer();
    $buffer->write(substr($bytes, 0, 5));
    expect(Record::parse($buffer))->toBeNull();

    $buffer->write(substr($bytes, 5));
    expect(Record::parse($buffer)->content)->toBe('hello');
});

test('parse() returns null until the content and padding have arrived, and keeps the header', function () {
    $bytes  = record_bytes(Record::FCGI_STDOUT, 'hello');
    $buffer = new StringBuffer();
    $buffer->write(substr($bytes, 0, 10));
    expect(Record::parse($buffer))->toBeNull();
    expect(Record::parse($buffer))->toBeNull();   // the header was put back, not lost

    $buffer->write(substr($bytes, 10, 3));
    expect(Record::parse($buffer))->toBeNull();   // the padding is still missing

    $buffer->write(substr($bytes, 13));
    $record = Record::parse($buffer);
    expect([$record->type, $record->requestId, $record->content])->toBe([Record::FCGI_STDOUT, 1, 'hello']);
});

test('parse() on an empty buffer returns null', function () {
    expect(Record::parse(new StringBuffer()))->toBeNull();
});

test('parse() reads consecutive records from one buffer', function () {
    $buffer = new StringBuffer();
    $buffer->write(record_bytes(Record::FCGI_STDIN, 'one', 1) . record_bytes(Record::FCGI_STDIN, 'two', 2));

    $first  = Record::parse($buffer);
    $second = Record::parse($buffer);
    expect([$first->requestId, $first->content])->toBe([1, 'one']);
    expect([$second->requestId, $second->content])->toBe([2, 'two']);
    expect(Record::parse($buffer))->toBeNull();
});

test('a record returned to the pool is empty and cannot be used', function () {
    $record = Record::create();
    $record->setStdout('data');
    $record->returnToPool();

    expect($record->content)->toBe('');
    expect(fn () => $record->setParams(['a' => 'b']))->toThrow(LogicException::class);
    expect(fn () => $record->getParams())->toThrow(LogicException::class);
    expect(fn () => $record->returnToPool())->toThrow(LogicException::class);

    // create() hands the pooled instance out again, usable
    $again = Record::create();
    $again->setParams(['a' => 'b']);
    expect($again->getParams())->toBe(['a' => 'b']);
});

// ---------------------------------------------------------------------------------------------
// UpgradeStream
// ---------------------------------------------------------------------------------------------

test('UpgradeStream reads what was appended, in order, in pieces of the requested size', function () {
    phasync::run(function () {
        $stream = new UpgradeStream();
        $stream->append('hello ', 1.0);
        $stream->appendNow('world');

        expect($stream->read(4))->toBe('hell');
        expect($stream->read(100))->toBe('o world');
        expect($stream->eof())->toBeFalse();
    });
});

test('UpgradeStream read() waits for bytes while the stream is open', function () {
    phasync::run(function () {
        $stream = new UpgradeStream();
        phasync::go(function () use ($stream) {
            phasync::sleep(0.02);
            $stream->append('late', 1.0);
        });
        expect($stream->read(10))->toBe('late');
    });
});

test('UpgradeStream read() on an ended stream returns what is left, then empty strings', function () {
    phasync::run(function () {
        $stream = new UpgradeStream();
        $stream->append('tail', 1.0);
        $stream->end();

        expect($stream->eof())->toBeFalse();   // bytes remain
        expect($stream->read(100))->toBe('tail');
        expect($stream->eof())->toBeTrue();
        expect($stream->read(100))->toBe('');
    });
});

test('UpgradeStream read() wakes with an empty string when the stream ends while it waits', function () {
    phasync::run(function () {
        $stream = new UpgradeStream();
        phasync::go(function () use ($stream) {
            phasync::sleep(0.02);
            $stream->end();
        });
        expect($stream->read(10))->toBe('');
        expect($stream->eof())->toBeTrue();
    });
});

test('UpgradeStream getContents() returns everything up to the end', function () {
    phasync::run(function () {
        $stream = new UpgradeStream();
        phasync::go(function () use ($stream) {
            $stream->append('a', 1.0);
            phasync::sleep(0.01);
            $stream->append('b', 1.0);
            $stream->end();
        });
        expect($stream->getContents())->toBe('ab');
    });
});

test('UpgradeStream append() waits while more than 64 kB is unread, until the reader takes some', function () {
    phasync::run(function () {
        $stream = new UpgradeStream();
        $log    = [];
        $writer = phasync::go(function () use ($stream, &$log) {
            $stream->append(str_repeat('x', 65536), 5.0);
            $log[] = 'full buffer accepted';          // exactly the buffer size does not wait
            $stream->append('y', 5.0);
            $log[] = 'append returned';
        });
        phasync::sleep(0.02);
        expect($log)->toBe(['full buffer accepted']);   // the second append waits for the reader

        expect(strlen($stream->read(10)))->toBe(10);
        phasync::await($writer);
        expect($log)->toBe(['full buffer accepted', 'append returned']);
    });
});

test('UpgradeStream append() times out when the reader never reads', function () {
    phasync::run(function () {
        $stream = new UpgradeStream();
        $stream->append(str_repeat('x', 65537), 0.05);
    });
})->throws(TimeoutException::class);

test('UpgradeStream keeps the bytes of an append() that timed out', function () {
    phasync::run(function () {
        $stream = new UpgradeStream();
        $start  = microtime(true);
        try {
            $stream->append(str_repeat('x', 70000), 0.05);
            $timedOut = false;
        } catch (TimeoutException) {
            $timedOut = true;
        }
        expect($timedOut)->toBeTrue();
        expect(microtime(true) - $start)->toBeGreaterThan(0.04)->toBeLessThan(1.0);
        expect(strlen($stream->read(100000)))->toBe(70000);
    });
});

test('UpgradeStream append() does not wait on a stream that has ended', function () {
    phasync::run(function () {
        $stream = new UpgradeStream();
        $stream->end();
        $stream->append(str_repeat('x', 70000), 0.05);
        expect(strlen($stream->read(100000)))->toBe(70000);
    });
});

test('UpgradeStream end() releases a blocked append()', function () {
    phasync::run(function () {
        $stream  = new UpgradeStream();
        $writer  = phasync::go(function () use ($stream) {
            $stream->append(str_repeat('x', 70000), 5.0);

            return 'released';
        });
        phasync::sleep(0.01);
        $stream->end();
        expect(phasync::await($writer))->toBe('released');
    });
});

test('UpgradeStream appendNow() goes past the buffer size without waiting', function () {
    phasync::run(function () {
        $stream = new UpgradeStream();
        $stream->appendNow(str_repeat('x', 100000));
        $stream->appendNow('!');
        expect(strlen($stream->read(200000)))->toBe(100001);
    });
});

test('UpgradeStream close() drops unread bytes and ends; detach() does the same and returns null', function () {
    phasync::run(function () {
        $stream = new UpgradeStream();
        $stream->appendNow('lost');
        $stream->close();
        expect($stream->eof())->toBeTrue();
        expect($stream->read(10))->toBe('');

        $other = new UpgradeStream();
        $other->appendNow('lost');
        expect($other->detach())->toBeNull();
        expect($other->eof())->toBeTrue();
    });
});

test('UpgradeStream is readable only, with no size, position or metadata', function () {
    $stream = new UpgradeStream();
    expect($stream->isReadable())->toBeTrue();
    expect($stream->isWritable())->toBeFalse();
    expect($stream->isSeekable())->toBeFalse();
    expect($stream->getSize())->toBeNull();
    expect($stream->getMetadata())->toBe([]);
    expect($stream->getMetadata('uri'))->toBeNull();
    expect((string) $stream)->toBe('');
});

test('UpgradeStream refuses tell(), seek(), rewind() and write()', function () {
    $stream = new UpgradeStream();
    expect(fn () => $stream->tell())->toThrow(RuntimeException::class, 'no position');
    expect(fn () => $stream->seek(0))->toThrow(RuntimeException::class, 'not seekable');
    expect(fn () => $stream->seek(0, SEEK_END))->toThrow(RuntimeException::class, 'not seekable');
    expect(fn () => $stream->rewind())->toThrow(RuntimeException::class, 'not seekable');
    expect(fn () => $stream->write('x'))->toThrow(RuntimeException::class, 'ProtocolUpgrade');
});

// ---------------------------------------------------------------------------------------------
// ProtocolUpgrade
// ---------------------------------------------------------------------------------------------

/** A request whose body is what the client sends after the handshake. */
function upgrade_request(string $clientBytes = '', array $headers = []): ServerRequest
{
    return new ServerRequest('GET', 'http://example.com/ws', StreamFactory::create($clientBytes), $headers);
}

test('ProtocolUpgrade answers with a 101 response carrying the handshake headers', function () {
    phasync::run(function () {
        $response = UpgradeProbe::from(upgrade_request(), static function () {});

        expect($response)->toBeInstanceOf(ResponseInterface::class);
        expect($response->getStatusCode())->toBe(101);
        expect($response->getHeaderLine('Connection'))->toBe('Upgrade');
        expect($response->getHeaderLine('Upgrade'))->toBe('probe');
        expect($response->getBody())->toBeInstanceOf(UpgradeStream::class);
    });
});

test('ProtocolUpgrade returns the handshake\'s refusal and does not run the callback', function () {
    phasync::run(function () {
        $ran      = false;
        $response = UpgradeProbe::from(upgrade_request('', ['X-Refuse' => '1']), function () use (&$ran) {
            $ran = true;
        });
        phasync::sleep(0.01);

        expect($response->getStatusCode())->toBe(403);
        expect((string) $response->getBody())->toBe('no');
        expect($response->hasHeader('Upgrade'))->toBeFalse();
        expect($ran)->toBeFalse();
    });
});

test('ProtocolUpgrade runs the callback on the connection and its writes are the body', function () {
    phasync::run(function () {
        $response = UpgradeProbe::from(upgrade_request(), function (UpgradeProbe $connection) {
            expect($connection->write('one '))->toBeTrue();
            expect($connection->writeNow('two'))->toBeTrue();
        });
        $body = $response->getBody();

        expect($body->getContents())->toBe('one two');   // the body ends when the callback returns
        expect($body->eof())->toBeTrue();
    });
});

test('ProtocolUpgrade reads the bytes the client sent after the handshake', function () {
    phasync::run(function () {
        $received = null;
        $response = UpgradeProbe::from(upgrade_request('from client'), function (UpgradeProbe $connection) use (&$received) {
            $received = [$connection->read(4), $connection->read(100), $connection->read(100)];
        });
        $response->getBody()->getContents();

        expect($received)->toBe(['from', ' client', '']);   // '' once the client's side has ended
    });
});

test('ProtocolUpgrade ends the body even when the callback throws', function () {
    phasync::run(function () {
        $response = UpgradeProbe::from(upgrade_request(), function () {
            throw new LogicException('boom');
        });
        expect($response->getBody()->getContents())->toBe('');
        expect($response->getBody()->eof())->toBeTrue();
    });
})->throws(LogicException::class, 'boom');

test('ProtocolUpgrade write() and writeNow() return false after end()', function () {
    phasync::run(function () {
        $results  = [];
        $response = UpgradeProbe::from(upgrade_request(), function (UpgradeProbe $connection) use (&$results) {
            $connection->write('before');
            $connection->end();
            $connection->end();   // ending twice is harmless
            $results = [$connection->write('after'), $connection->writeNow('after')];
        });

        expect($response->getBody()->getContents())->toBe('before');
        expect($results)->toBe([false, false]);
    });
});

test('ProtocolUpgrade gives up on a client that stopped reading: write() returns false and the connection ends', function () {
    phasync::run(function () {
        $clientBody = StreamFactory::create('unread');
        $request    = new ServerRequest('GET', 'http://example.com/ws', $clientBody);
        $result     = null;
        $response   = UpgradeProbe::from($request, function (UpgradeProbe $connection) use (&$result) {
            $result = [$connection->write(str_repeat('x', 70000)), $connection->write('more')];
        });
        $body = $response->getBody();
        phasync::sleep(0.2);   // nobody reads the response; WRITE_TIMEOUT of the probe is 0.05 s

        expect($result)->toBe([false, false]);
        expect(strlen($body->getContents()))->toBe(70000);
        expect($body->eof())->toBeTrue();
        expect(fn () => $clientBody->read(1))->toThrow(RuntimeException::class);   // the request body was closed
    });
});

test('ProtocolUpgrade has a 30 second default write timeout', function () {
    expect((new ReflectionClassConstant(ProtocolUpgrade::class, 'WRITE_TIMEOUT'))->getValue())->toBe(30.0);
});
