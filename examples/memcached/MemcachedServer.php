<?php

namespace Example;

use phasync;
use phasync\TimeoutException;
use Swerve\Swerve;

/**
 * A memcached server (text protocol) whose storage is `Swerve::cache()`, so every worker serves the same data.
 *
 * Each worker runs one of these and listens on the same port with SO_REUSEPORT: the kernel
 * gives every new connection to one of them, and a connection stays with its worker. Reads go
 * straight to the cache. Every write takes a `Swerve::claim()` on its key, which is what makes
 * cas, incr, decr, append and prepend atomic across the workers.
 *
 * ```php
 * (new MemcachedServer(11211))->start();   // in swerve.php
 * ```
 */
final class MemcachedServer
{
    private const MAX_VALUE   = 1048576;
    private const MAX_LINE    = 65536;
    private const MAX_KEY     = 250;
    private const MAX_UINT32  = 4294967295;
    private const THIRTY_DAYS = 2592000;

    /** @var resource */
    private mixed $listener;
    /** The coroutines of the connections that wait for their next command, by connection number. */
    private array $idle = [];
    private int $connections = 0;
    private array $counters  = ['total_connections' => 0, 'cmd_get' => 0, 'cmd_set' => 0, 'cmd_touch' => 0, 'get_hits' => 0, 'get_misses' => 0];
    private readonly int $startedAt;

    public function __construct(private readonly int $port)
    {
        $this->startedAt = \time();
    }

    /**
     * Listen on the port and accept connections in a coroutine of its own.
     *
     * Call it from swerve.php, anywhere: the coroutine starts when the loading ends, and a command
     * that needs the cache waits until the worker serves. When the worker drains it stops accepting,
     * closes the connections that are waiting for a command, and the others close after the
     * command in progress.
     *
     * @throws \RuntimeException when the port can't be listened on
     */
    public function start(): void
    {
        $context  = \stream_context_create(['socket' => ['so_reuseport' => true, 'tcp_nodelay' => true, 'backlog' => 1024]]);
        $listener = @\stream_socket_server("tcp://0.0.0.0:{$this->port}", $errno, $error, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, $context);
        if (false === $listener) {
            throw new \RuntimeException("Could not listen on port {$this->port}: $error");
        }
        \stream_set_blocking($listener, false);
        $this->listener = $listener;
        phasync::go($this->accept(...));
    }

    /**
     * The counters of this worker, as `stats` reports them.
     *
     * @return array<string, int|string>
     */
    public function stats(): array
    {
        return ['pid' => \getmypid(), 'uptime' => \time() - $this->startedAt, 'time' => \time(), 'version' => '1.6.0', 'curr_connections' => $this->connections] + $this->counters;
    }

    private function accept(): void
    {
        $number = 0;
        while (!Swerve::draining()) {
            try {
                phasync::readable($this->listener, 0.25);
            } catch (TimeoutException) {
                continue;   // looks at draining() again
            }
            while (false !== ($socket = @\stream_socket_accept($this->listener, 0))) {
                \stream_set_blocking($socket, false);
                $id = ++$number;
                phasync::go(fn () => $this->serve($socket, $id));
            }
        }
        \fclose($this->listener);
        foreach ($this->idle as $fiber) {
            phasync::cancel($fiber);
        }
    }

    /** One connection: read a command, answer it, repeat until the client leaves. */
    private function serve(mixed $socket, int $id): void
    {
        ++$this->connections;
        ++$this->counters['total_connections'];
        $buffer = '';
        try {
            while (!Swerve::draining()) {
                $this->idle[$id] = \Fiber::getCurrent();
                $line            = $this->readLine($socket, $buffer);
                unset($this->idle[$id]);
                if (null === $line || null === ($reply = $this->command($socket, $buffer, $line))) {
                    break;
                }
                if ('' !== $reply && !$this->write($socket, $reply)) {
                    break;
                }
            }
        } finally {
            unset($this->idle[$id]);
            --$this->connections;
            \fclose($socket);
        }
    }

    /**
     * Run one command line, reading the data block that a storage command has after it.
     *
     * @return string|null the reply, empty for none (`noreply`); null to close the connection
     */
    private function command(mixed $socket, string &$buffer, string $line): ?string
    {
        $tokens  = \array_values(\array_filter(\explode(' ', $line), 'strlen'));
        $command = \array_shift($tokens);
        if ('get' === $command || 'gets' === $command) {
            return $tokens ? $this->get('gets' === $command, $tokens) : "ERROR\r\n";
        }
        if ('quit' === $command) {
            return null;
        }
        if ('version' === $command) {
            return "VERSION 1.6.0\r\n";
        }
        if ('stats' === $command) {
            $reply = '';
            foreach ($tokens ? [] : $this->stats() as $name => $value) {
                $reply .= "STAT $name $value\r\n";
            }

            return $reply . "END\r\n";
        }
        $noreply = \in_array($command, ['set', 'add', 'replace', 'append', 'prepend', 'cas', 'delete', 'incr', 'decr', 'touch', 'flush_all'], true)
            && 'noreply' === \end($tokens);
        if ($noreply) {
            \array_pop($tokens);
        }
        $reply = match ($command) {
            'set', 'add', 'replace', 'append', 'prepend', 'cas' => $this->store($command, $tokens, $socket, $buffer),
            'delete'                                            => $this->delete($tokens),
            'incr', 'decr'                                      => $this->arithmetic('incr' === $command, $tokens),
            'touch'                                             => $this->touch($tokens),
            'flush_all'                                         => $this->flush($tokens),
            default                                             => "ERROR\r\n",
        };

        return $noreply ? '' : $reply;
    }

    /** @param list<string> $keys */
    private function get(bool $withCas, array $keys): string
    {
        $cacheKeys = [];
        foreach ($keys as $key) {
            if (!self::validKey($key)) {
                return "CLIENT_ERROR bad command line format\r\n";
            }
            $cacheKeys[] = self::cacheKey($key);
        }
        $found = Swerve::cache()->getMultiple($cacheKeys);
        $reply = '';
        foreach ($keys as $i => $key) {
            ++$this->counters['cmd_get'];
            $entry = self::live($found[$cacheKeys[$i]]);
            if (null === $entry) {
                ++$this->counters['get_misses'];
                continue;
            }
            ++$this->counters['get_hits'];
            [$flags, $bytes, $cas] = $entry;
            $reply .= "VALUE $key $flags " . \strlen($bytes) . ($withCas ? " $cas" : '') . "\r\n$bytes\r\n";
        }

        return $reply . "END\r\n";
    }

    /** @param list<string> $tokens */
    private function store(string $command, array $tokens, mixed $socket, string &$buffer): ?string
    {
        $cas = 'cas' === $command;
        if (\count($tokens) !== ($cas ? 5 : 4)) {
            return "ERROR\r\n";
        }
        [$key, $flags, $exptime, $length] = $tokens;
        $flags  = self::int($flags);
        $length = self::int($length);
        $unique = $cas ? self::int($tokens[4]) : 0;
        if (!self::validKey($key) || null === $flags || $flags > self::MAX_UINT32 || !\preg_match('/^-?\d+$/D', $exptime) || null === $length || null === $unique) {
            return "CLIENT_ERROR bad command line format\r\n";
        }
        if ($length > self::MAX_VALUE) {
            // What memcached does: the old value goes too, and the data block is read and thrown away
            if ('set' === $command) {
                $this->locked($key, static function () use ($key) {
                    Swerve::cache()->delete(self::cacheKey($key));

                    return '';
                });
            }

            return $this->swallow($socket, $buffer, $length + 2) ? "SERVER_ERROR object too large for cache\r\n" : null;
        }
        if (null === ($block = $this->readBlock($socket, $buffer, $length))) {
            return null;
        }
        if (null === $block[0]) {
            return "CLIENT_ERROR bad data chunk\r\n";
        }
        $data = $block[0];
        ++$this->counters['cmd_set'];

        return $this->locked($key, function () use ($command, $key, $flags, $exptime, $data, $unique) {
            $cacheKey = self::cacheKey($key);
            $old      = self::live(Swerve::cache()->get($cacheKey));
            switch ($command) {
                case 'add':
                    if (null !== $old) {
                        return "NOT_STORED\r\n";
                    }
                    break;
                case 'replace':
                    if (null === $old) {
                        return "NOT_STORED\r\n";
                    }
                    break;
                case 'append':
                case 'prepend':
                    if (null === $old) {
                        return "NOT_STORED\r\n";
                    }
                    // The flags and the expiry stay as they were
                    [$flags, $bytes, , $expires] = $old;
                    $data                        = 'append' === $command ? $bytes . $data : $data . $bytes;
                    if (\strlen($data) > self::MAX_VALUE) {
                        return "SERVER_ERROR object too large for cache\r\n";
                    }
                    $exptime = null;
                    break;
                case 'cas':
                    if (null === $old) {
                        return "NOT_FOUND\r\n";
                    }
                    if ($old[2] !== $unique) {
                        return "EXISTS\r\n";
                    }
                    break;
            }

            return $this->put($cacheKey, $flags, $data, $expires ?? self::expiry((int) $exptime)) ? "STORED\r\n" : "SERVER_ERROR out of memory storing object\r\n";
        });
    }

    /** @param list<string> $tokens */
    private function delete(array $tokens): string
    {
        if (!$tokens || \count($tokens) > 2) {
            return "ERROR\r\n";
        }
        if (!self::validKey($tokens[0]) || (isset($tokens[1]) && '0' !== $tokens[1])) {
            return "CLIENT_ERROR bad command line format.  Usage: delete <key> [noreply]\r\n";
        }

        return $this->locked($tokens[0], function () use ($tokens) {
            $cacheKey = self::cacheKey($tokens[0]);
            if (null === self::live(Swerve::cache()->get($cacheKey))) {
                return "NOT_FOUND\r\n";
            }
            Swerve::cache()->delete($cacheKey);

            return "DELETED\r\n";
        });
    }

    /** @param list<string> $tokens */
    private function arithmetic(bool $increment, array $tokens): string
    {
        if (2 !== \count($tokens)) {
            return "ERROR\r\n";
        }
        [$key, $delta] = $tokens;
        if (!self::validKey($key)) {
            return "CLIENT_ERROR bad command line format\r\n";
        }
        if (null === ($delta = self::int($delta))) {
            return "CLIENT_ERROR invalid numeric delta argument\r\n";
        }

        return $this->locked($key, function () use ($key, $increment, $delta) {
            $cacheKey = self::cacheKey($key);
            if (null === ($old = self::live(Swerve::cache()->get($cacheKey)))) {
                return "NOT_FOUND\r\n";
            }
            [$flags, $bytes, , $expires] = $old;
            if (null === ($number = self::int($bytes))) {
                return "CLIENT_ERROR cannot increment or decrement non-numeric value\r\n";
            }
            $number = $increment ? $number + $delta : \max(0, $number - $delta);
            if (\is_float($number)) {
                return "CLIENT_ERROR increment or decrement overflow\r\n";
            }

            return $this->put($cacheKey, $flags, (string) $number, $expires) ? "$number\r\n" : "SERVER_ERROR out of memory storing object\r\n";
        });
    }

    /** @param list<string> $tokens */
    private function touch(array $tokens): string
    {
        if (2 !== \count($tokens)) {
            return "ERROR\r\n";
        }
        [$key, $exptime] = $tokens;
        if (!self::validKey($key) || !\preg_match('/^-?\d+$/D', $exptime)) {
            return "CLIENT_ERROR invalid exptime argument\r\n";
        }
        ++$this->counters['cmd_touch'];

        return $this->locked($key, function () use ($key, $exptime) {
            $cacheKey = self::cacheKey($key);
            if (null === ($old = self::live(Swerve::cache()->get($cacheKey)))) {
                return "NOT_FOUND\r\n";
            }
            // A touch is not a write of the value: the cas id stays
            [$flags, $bytes, $cas] = $old;

            return $this->put($cacheKey, $flags, $bytes, self::expiry((int) $exptime), $cas) ? "TOUCHED\r\n" : "SERVER_ERROR out of memory storing object\r\n";
        });
    }

    /** @param list<string> $tokens */
    private function flush(array $tokens): string
    {
        if (\count($tokens) > 1) {
            return "ERROR\r\n";
        }
        if ($tokens && '0' !== $tokens[0]) {
            return "CLIENT_ERROR a delayed flush_all is not supported\r\n";
        }
        Swerve::cache()->clear();

        return "OK\r\n";
    }

    /**
     * Store an entry, `[flags, bytes, cas, expires]`, in the cache; an expiry in the past deletes it.
     *
     * Every write gets a new random cas id, which needs no coordination between the workers.
     *
     * @param int $expires unix time, 0 for never
     * @param int $cas     the id to keep, for a touch
     */
    private function put(string $cacheKey, int $flags, string $bytes, int $expires, ?int $cas = null): bool
    {
        return Swerve::cache()->set($cacheKey, [$flags, $bytes, $cas ?? \random_int(1, \PHP_INT_MAX - 1), $expires], 0 === $expires ? null : $expires - \time());
    }

    /**
     * Run `$fn` while holding the claim on `$key`, so that no other worker changes that key meanwhile.
     *
     * @param \Closure(): string $fn
     */
    private function locked(string $key, \Closure $fn): string
    {
        if (null === ($claim = Swerve::claim("mc:$key")->acquire(5.0))) {
            return "SERVER_ERROR key is busy\r\n";
        }
        try {
            return $fn();
        } finally {
            $claim->release();
        }
    }

    /** The unix time memcached's `exptime` means: 0 never, negative already, up to 30 days from now, beyond that an absolute time. */
    private static function expiry(int $exptime): int
    {
        if (0 === $exptime) {
            return 0;
        }
        if ($exptime < 0) {
            return 1;   // in the past
        }

        return $exptime > self::THIRTY_DAYS ? $exptime : \time() + $exptime;
    }

    /** The entry, or null when there is none or its time has passed. */
    private static function live(?array $entry): ?array
    {
        return null === $entry || (0 !== $entry[3] && $entry[3] <= \time()) ? null : $entry;
    }

    /** What the cache takes as a key: the percent-encoded memcached key, which has none of the characters the cache refuses. */
    private static function cacheKey(string $key): string
    {
        return \rawurlencode($key);
    }

    private static function validKey(string $key): bool
    {
        return \strlen($key) <= self::MAX_KEY && !\preg_match('/[\x00-\x20\x7f]/', $key);
    }

    /** A non-negative decimal that fits in 63 bits, or null. */
    private static function int(string $digits): ?int
    {
        return \ctype_digit($digits) && (string) (int) ($trimmed = \ltrim($digits, '0') ?: '0') === $trimmed ? (int) $trimmed : null;
    }

    /** One line without its line ending; null when the client has gone. */
    private function readLine(mixed $socket, string &$buffer): ?string
    {
        while (false === ($end = \strpos($buffer, "\n"))) {
            if (\strlen($buffer) > self::MAX_LINE) {
                $this->write($socket, "CLIENT_ERROR line too long\r\n");

                return null;
            }
            if (!$this->fill($socket, $buffer)) {
                return null;
            }
        }
        $line   = \substr($buffer, 0, $end);
        $buffer = \substr($buffer, $end + 1);

        return \rtrim($line, "\r");
    }

    /**
     * A data block of `$length` bytes and the line ending after it.
     *
     * @return array{0: string|null}|null the data, or null in it when the line ending is wrong; null when the client has gone
     */
    private function readBlock(mixed $socket, string &$buffer, int $length): ?array
    {
        while (\strlen($buffer) < $length + 2) {
            if (!$this->fill($socket, $buffer)) {
                return null;
            }
        }
        $ok     = "\r\n" === \substr($buffer, $length, 2);
        $data   = \substr($buffer, 0, $length);
        $buffer = \substr($buffer, $length + 2);

        return [$ok ? $data : null];
    }

    /** Read and discard `$count` bytes; false when the client has gone. */
    private function swallow(mixed $socket, string &$buffer, int $count): bool
    {
        while ($count > 0) {
            if ('' === $buffer && !$this->fill($socket, $buffer)) {
                return false;
            }
            $take   = \min($count, \strlen($buffer));
            $buffer = \substr($buffer, $take);
            $count -= $take;
        }

        return true;
    }

    /** Wait until the socket has something and add it to the buffer; false when the client has gone. */
    private function fill(mixed $socket, string &$buffer): bool
    {
        phasync::readable($socket, \PHP_FLOAT_MAX);
        $data = \fread($socket, 65536);
        if (false === $data || ('' === $data && \feof($socket))) {
            return false;
        }
        $buffer .= $data;

        return true;
    }

    /** Send all of `$data`, waiting while the client's buffer is full; false when the client has gone or stopped reading for 30 s. */
    private function write(mixed $socket, string $data): bool
    {
        try {
            while ('' !== $data) {
                $written = @\fwrite($socket, $data);
                if (false === $written) {
                    return false;
                }
                $data = \substr($data, $written);
                if ('' !== $data) {
                    phasync::writable($socket, 30);
                }
            }
        } catch (TimeoutException) {
            return false;
        }

        return true;
    }
}
