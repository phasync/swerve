<?php

/*
 * WebSocket::run(): the adapter entry for a connection whose 101 was sent by someone else, over a
 * plain phasync\Net\Duplex (no ClientRequest at all). The codec itself (frames, close codes) is
 * pinned over the wire in WebSocketTest.php; this is only about run() accepting any Duplex.
 */

use phasync\Net\Duplex;
use Swerve\WebSocket;

/** A loopback Duplex: what is write()n goes to $sent; feed() queues bytes as if the peer sent them. */
final class MemoryDuplex implements Duplex
{
    private string $inbox = '';
    public string $sent   = '';
    private bool $ended   = false;
    private bool $closed  = false;
    private object $flag;

    public function __construct()
    {
        $this->flag = new \stdClass();
    }

    public function feed(string $bytes): void
    {
        $this->inbox .= $bytes;
        phasync::raiseFlag($this->flag);
    }

    public function read(int $max = 65536, ?float $timeout = null): string
    {
        while ('' === $this->inbox && !$this->ended && !$this->closed) {
            phasync::awaitFlag($this->flag, $timeout ?? \PHP_FLOAT_MAX);
        }
        if ('' === $this->inbox) {
            return '';
        }
        $bytes       = \substr($this->inbox, 0, $max);
        $this->inbox = \substr($this->inbox, \strlen($bytes));

        return $bytes;
    }

    public function write(string $bytes, ?float $timeout = null): void
    {
        $this->sent .= $bytes;
    }

    public function eof(): bool
    {
        return '' === $this->inbox && ($this->ended || $this->closed);
    }

    public function pending(): bool
    {
        return '' !== $this->inbox;
    }

    public function end(): void
    {
        $this->ended = true;
        phasync::raiseFlag($this->flag);
    }

    public function close(): void
    {
        $this->closed = true;
        phasync::raiseFlag($this->flag);
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function peer(): string
    {
        return 'memory:0';
    }

    public function local(): string
    {
        return 'memory:0';
    }
}

function masked_frame(int $byte0, string $payload): string
{
    $n    = \strlen($payload);
    $mask = "\x37\xfa\x21\x3d";
    $len  = $n < 126 ? \chr(0x80 | $n) : \chr(0x80 | 126) . \pack('n', $n);

    return \chr($byte0) . $len . $mask . ($payload ^ \substr(\str_repeat($mask, \intdiv($n, 4) + 1), 0, $n));
}

/** @return array{0: int, 1: string, 2: string} opcode, payload, the bytes after the frame */
function unmasked_frame(string $bytes): array
{
    $opcode = \ord($bytes[0]) & 0x0F;
    $length = \ord($bytes[1]) & 0x7F;
    $offset = 2;
    if (126 === $length) {
        $length = \unpack('n', \substr($bytes, 2, 2))[1];
        $offset = 4;
    }

    return [$opcode, \substr($bytes, $offset, $length), \substr($bytes, $offset + $length)];
}

test('run() echoes a message and ends 1000 on return, over a Duplex that is not a ClientRequest', function () {
    $connection = new MemoryDuplex();
    $connection->feed(masked_frame(0x81, 'hi')); // FIN + text

    phasync::run(function () use ($connection) {
        WebSocket::run($connection, function (WebSocket $ws) {
            $ws->send(\strtoupper($ws->receive()));
            $ws->end(1000);
        }, subprotocol: 'demo');
    });

    [$opcode, $payload, $rest] = unmasked_frame($connection->sent);
    expect($opcode)->toBe(1);
    expect($payload)->toBe('HI');
    [$opcode, $payload] = unmasked_frame($rest);
    expect($opcode)->toBe(8);
    expect(\unpack('n', $payload)[1])->toBe(1000);
    expect($connection->isClosed())->toBeFalse(); // end() only ends our side; close() is for a dead peer
});
