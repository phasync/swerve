<?php

/*
 * Pure unit tests, no server and no sockets: the UpgradeStream that carries an
 * upgraded connection's output, and the ProtocolUpgrade response around it.
 */

use phasync\Psr\Response;
use phasync\Psr\ServerRequest;
use phasync\Psr\StreamFactory;
use phasync\TimeoutException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
