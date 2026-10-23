<?php

namespace Swerve\Util\Classic;

use phasync\IOException;
use Swerve\Cache;
use Swerve\SubscriberLagException;
use Swerve\Subscription;
use Swerve\Util\Topics;

/**
 * A worker's service socket: Swerve's cache and publish/subscribe for the scripts of a Classic
 * application, which run in processes of their own (the request server's, or php -S) and have no
 * channel to the master. Every request a worker forwards names this socket (see
 * {@see ServiceServer::HEADER}); the script's client (classic-client.php, prepended to every
 * script) connects on its first call.
 *
 * Frames: `[type: 1 byte][length: 4 bytes, big-endian][payload]`, as the request server's. A CALL
 * is `serialize([op, ...arguments])` of strings, ints and arrays; its REPLY is
 * `serialize([true, result])` or `serialize([false, exception class, message])`. Cache values
 * travel as the client serialized them, and nobody here unserializes them: the worker doesn't
 * have the application's classes. A `subscribe` call turns its connection into the subscription:
 * JSON (a published message), BYTES (a string message), HEARTBEAT (none came within the
 * heartbeat) and END frames, until the client closes it.
 *
 * @internal
 */
final class ServiceServer
{
    /** The request header that names the socket, which no client may send. */
    public const HEADER = 'x-swerve-services';

    /** The response header of a script that handed its client over (WebSocket::setup()): the id. */
    public const HANDOVER = 'x-swerve-handover';

    /** Seconds a handover waits to be taken, after which nobody will. */
    private const HANDOVER_TTL = 30.0;

    /** @var array<string, array{string, float}> handover id => Serializor's payload, and when it came */
    private static array $handovers = [];

    public const CALL      = 1;
    public const REPLY     = 2;
    public const JSON      = 3;
    public const BYTES     = 4;
    public const HEARTBEAT = 5;
    public const END       = 6;

    /** @var resource|null */
    private $listener = null;

    private bool $draining = false;

    /** @var array<int, resource> the open connections, to close them on drain */
    private array $connections = [];

    public function __construct(public readonly string $path)
    {
    }

    /**
     * Listen on the socket, accessible to this user only.
     *
     * @throws \RuntimeException when it can't
     */
    public function listen(): void
    {
        @\unlink($this->path);
        $listener = @\stream_socket_server("unix://{$this->path}", $errno, $error);
        if (false === $listener) {
            throw new \RuntimeException("Can't listen on the service socket {$this->path}: $error");
        }
        \chmod($this->path, 0600);
        \stream_set_blocking($listener, false);
        $this->listener = $listener;
    }

    /** Accept connections until drained. */
    public function run(): void
    {
        try {
            while (!$this->draining) {
                \phasync::readable($this->listener);
                while (false !== $conn = @\stream_socket_accept($this->listener, 0)) {
                    \stream_set_blocking($conn, false);
                    $this->connections[(int) $conn] = $conn;
                    \phasync::go(fn () => $this->serve($conn));
                }
            }
        } finally {
            @\fclose($this->listener);
            @\unlink($this->path);
        }
    }

    /** Stop accepting, and close the open connections: their scripts' calls fail. */
    public function drain(bool $linger = false): void
    {
        $this->draining = true;
        foreach ($this->connections as $conn) {
            @\stream_socket_shutdown($conn, \STREAM_SHUT_RDWR);
        }
        // Wakes run() from its wait for a connection
        if (null !== $this->listener) {
            @\stream_socket_shutdown($this->listener, \STREAM_SHUT_RDWR);
        }
    }

    /** @param resource $conn */
    private function serve($conn): void
    {
        $buffer = '';
        try {
            while (null !== $frame = self::read($conn, $buffer)) {
                [$type, $payload] = $frame;
                if (self::CALL !== $type) {
                    throw new \UnexpectedValueException("A service client sent a frame of type $type");
                }
                $call = \unserialize($payload, ['allowed_classes' => false]);
                if ('subscribe' === $call[0]) {
                    $this->subscribe($conn, $buffer, ...\array_slice($call, 1));

                    return;
                }
                try {
                    $reply = [true, $this->call($call)];
                } catch (\InvalidArgumentException|\Psr\SimpleCache\InvalidArgumentException $e) {
                    $reply = [false, $e::class, $e->getMessage()];
                }
                self::write($conn, self::REPLY, \serialize($reply));
            }
        } catch (IOException) {
            // The script's process ended, or the worker drains
        } finally {
            unset($this->connections[(int) $conn]);
            @\fclose($conn);
        }
    }

    private function call(array $call): mixed
    {
        return match ($call[0]) {
            'cache.get'    => Cache::instance()->getSerialized($call[1]),
            'cache.set'    => Cache::instance()->setSerialized($call[1], $call[2]),
            'cache.delete' => Cache::instance()->deleteMultiple($call[1]),
            'cache.clear'  => Cache::instance()->clear(),
            'publish'      => self::publish($call[1], $call[2]),
            'handover'     => self::store($call[1]),
        };
    }

    /** Keep what a script's WebSocket::setup() serialized, for the worker that sees its response head. */
    private static function store(string $payload): string
    {
        $now = \microtime(true);
        foreach (self::$handovers as $id => [, $at]) {
            if ($now - $at > self::HANDOVER_TTL) {
                unset(self::$handovers[$id]);
            }
        }
        $id                    = \bin2hex(\random_bytes(16));
        self::$handovers[$id] = [$payload, $now];

        return $id;
    }

    /**
     * What a script handed over under $id: taken once.
     *
     * @throws \UnexpectedValueException for an unknown or expired id
     */
    public static function take(string $id): string
    {
        $payload = self::$handovers[$id][0] ?? throw new \UnexpectedValueException("A script named the handover $id, which this worker doesn't have");
        unset(self::$handovers[$id]);

        return $payload;
    }

    private static function publish(string $topic, string $encoded): null
    {
        if (\str_starts_with($topic, "\0")) {
            throw new \InvalidArgumentException('Topics starting with "\\0" are swerve\'s own');
        }
        Topics::publishEncodedBy('Swerve::publish', $topic, $encoded);

        return null;
    }

    /**
     * The connection becomes the subscription: each message as a frame, until the client closes it.
     *
     * @param resource $conn
     */
    private function subscribe($conn, string $buffer, string $topic, float $maxLag, ?float $heartbeat): void
    {
        if (\str_starts_with($topic, "\0")) {
            self::write($conn, self::REPLY, \serialize([false, \InvalidArgumentException::class, 'Topics starting with "\\0" are swerve\'s own']));

            return;
        }
        $subscription = new Subscription($topic, $maxLag, $heartbeat);
        self::write($conn, self::REPLY, \serialize([true, null]));
        $forward = \phasync::go(static function () use ($conn, $subscription): void {
            try {
                foreach ($subscription as $message) {
                    if (null === $message) {
                        self::write($conn, self::HEARTBEAT, '');
                    } elseif (\is_string($message)) {
                        self::write($conn, self::BYTES, $message);
                    } else {
                        self::write($conn, self::JSON, \json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION));
                    }
                }
                self::write($conn, self::END, '');
            } catch (SubscriberLagException $e) {
                self::write($conn, self::END, $e->getMessage());
            }
        });
        // The client closing the connection ends the subscription
        try {
            while (null !== self::read($conn, $buffer)) {
            }
        } finally {
            \phasync::cancel($forward);
        }
    }

    /**
     * The next frame, or null when the connection ended between frames.
     *
     * @param resource $conn
     *
     * @return array{int, string}|null
     *
     * @throws IOException when it ends inside one
     */
    private static function read($conn, string &$buffer): ?array
    {
        while (\strlen($buffer) < 5 || \strlen($buffer) < 5 + \unpack('N', $buffer, 1)[1]) {
            $bytes = @\fread($conn, 65536);
            if ('' === $bytes || false === $bytes) {
                if (\feof($conn)) {
                    if ('' === $buffer) {
                        return null;
                    }
                    throw new IOException('A service connection ended inside a frame');
                }
                \phasync::readable($conn);
                continue;
            }
            $buffer .= $bytes;
        }
        $length = \unpack('N', $buffer, 1)[1];
        $frame  = [\ord($buffer[0]), \substr($buffer, 5, $length)];
        $buffer = \substr($buffer, 5 + $length);

        return $frame;
    }

    /**
     * @param resource $conn
     *
     * @throws IOException when the connection is gone
     */
    private static function write($conn, int $type, string $payload): void
    {
        $bytes = \pack('CN', $type, \strlen($payload)) . $payload;
        while ('' !== $bytes) {
            $n = @\fwrite($conn, $bytes);
            if (false === $n) {
                throw new IOException('A service connection closed');
            }
            $bytes = \substr($bytes, $n);
            if ('' !== $bytes) {
                \phasync::writable($conn);
            }
        }
    }
}
