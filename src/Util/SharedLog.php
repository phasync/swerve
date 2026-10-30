<?php

namespace Swerve\Util;

use phasync;
use phasync\CancelledException;
use phasync\TimeoutException;
use Swerve\Swerve;

/**
 * Publish/subscribe across the workers of one swerve: a log in APCu, which every process shares.
 *
 * A message takes the next sequence number (`apcu_inc()`) and goes in the slot of a ring of
 * `SWERVE_PUBSUB_RING` slots (`--pubsub-ring`, 8192 by default). The sequence orders every message
 * of every publisher: all subscribers, in every worker, receive them in that one order. A
 * process with subscriptions reads the log from where it subscribed, and hands each message to
 * its subscribers of the topic; it skips the others, and reads on until it drains, also when its
 * subscriptions have ended. The master tracks nothing.
 *
 * APCu can't wake a process: a publisher rings a doorbell, a one-byte datagram to the socket of
 * every process with subscriptions, which binds it as its first subscription starts (an abstract
 * name on Linux, a file in the temporary directory elsewhere). A doorbell already ringing stays
 * one: a burst wakes a reader once, and it reads all of it.
 *
 * Delivery is at most once, and a loss is never silent: a reader that falls a whole ring behind
 * finds its next slot overwritten, and every subscriber of the process gets a gap (Subscription
 * throws SubscriberLagException); from 3/4 of the ring behind it logs a warning. A sequence number
 * taken by a publisher that died before it wrote the slot is skipped after a second.
 *
 * Messages go in APCu's memory: a ring of R slots holds up to R messages of up to 128 KiB, so
 * `apc.shm_size` must leave room for it beside the cache. When APCu runs out and expunges, the
 * log starts over, and the readers report a gap.
 *
 * @internal
 */
final class SharedLog
{
    private const PREFIX = 'swerve:log:';

    /** The sequence number of the last message published. */
    private const HEAD = self::PREFIX . 'head';

    /** pid => doorbell address of the processes with subscriptions. */
    private const READERS = self::PREFIX . 'readers';

    /** Seconds a reader waits for a slot whose sequence number was taken, before it skips it. */
    private const ABANDONED = 1.0;

    /** Slots in the ring, unless SWERVE_PUBSUB_RING says. */
    public const DEFAULT_RING = 8192;

    /** Whether this process is a swerve worker, which shares the log with the others. */
    public static function shared(): bool
    {
        return false !== \getenv('SWERVE_MASTER') && \function_exists('apcu_enabled') && \apcu_enabled();
    }

    /** @var array<int, resource> connected doorbells of other processes, by pid */
    private static array $doorbells = [];

    /** This process's reader, while it has subscriptions. */
    private static ?self $reader = null;

    /** @var resource this reader's doorbell */
    private mixed $own;

    /** The next sequence number to read. */
    private int $next;

    /** Behind by 3/4 of the ring or more: warned once, until it catches up. */
    private bool $warned = false;

    /** stop() ended it: a reader stopped during a delivery reads no further. */
    private bool $stopped = false;

    /** Null while it registers. */
    private ?\Fiber $fiber = null;

    private function __construct()
    {
    }

    /** Append a message (encoded as Topics encodes it) and ring the readers' doorbells. */
    public static function append(string $topic, string $message): void
    {
        $seq = \apcu_inc(self::HEAD);
        if (false === $seq) {
            \apcu_add(self::HEAD, 0); // a new log, or APCu expunged it
            $seq = \apcu_inc(self::HEAD);
        }
        \apcu_store(self::PREFIX . ($seq % self::ringSize()), [$seq, \hrtime(true), $topic, $message]);
        foreach (\apcu_fetch(self::READERS) ?: [] as $pid => $address) {
            self::ringBell($pid, $address);
        }
    }

    /**
     * Start reading as this process's first subscription starts: from the next message published.
     *
     * @param \Closure(int $seq, int $published, string $topic, string $message): void $deliver
     * @param \Closure(): void                                                          $gap     messages were lost
     *
     * @return int the sequence number of the last message published before
     */
    public static function start(\Closure $deliver, \Closure $gap): int
    {
        if (null === self::$reader) {
            $reader      = new self();
            $address     = self::address(\getmypid());
            $reader->own = \stream_socket_server("udg://$address", $errno, $error, \STREAM_SERVER_BIND)
                ?: throw new \RuntimeException("Binding the pub/sub doorbell $address failed: $error");
            \stream_set_blocking($reader->own, false);
            self::$reader = $reader; // before register(), which may wait: one reader per process
            self::register(\getmypid(), $address);
            $reader->next  = self::head() + 1;
            if (!$reader->stopped) {
                $reader->fiber = phasync::service(static fn () => $reader->read($deliver, $gap));
            }
        }

        return self::head();
    }

    /** The process drains: it stops reading. */
    public static function stop(): void
    {
        if (null === self::$reader) {
            return;
        }
        $reader          = self::$reader;
        self::$reader    = null;
        $reader->stopped = true;
        self::unregister(\getmypid());
        if (null !== $reader->fiber && $reader->fiber !== \Fiber::getCurrent()) {
            phasync::cancel($reader->fiber);
        }
        \fclose($reader->own); // the next reader binds the address anew
    }

    /** Forget a process that is gone (the master, as it reaps a child). */
    public static function forget(int $pid): void
    {
        self::unregister($pid);
    }

    /**
     * @param \Closure(int, int, string, string): void $deliver
     * @param \Closure(): void                         $gap
     */
    private function read(\Closure $deliver, \Closure $gap): void
    {
        $ring    = self::ringSize();
        $missing = null; // since when the next slot is missing
        while (!$this->stopped) {
            $head = self::head();
            if ($head < $this->next - 1) {
                // The head went back: APCu expunged the log
                $this->next = $head + 1;
                $gap();
            }
            while ($this->next <= $head) {
                $entry = \apcu_fetch(self::PREFIX . ($this->next % $ring));
                if (false === $entry || $entry[0] < $this->next) {
                    // Taken, not yet written: its publisher rings once it is; skipped if it never is
                    $missing ??= \microtime(true);
                    if (\microtime(true) - $missing < self::ABANDONED) {
                        break;
                    }
                    ++$this->next;
                    $missing = null;
                    $gap();
                    continue;
                }
                $missing = null;
                if ($entry[0] > $this->next) {
                    // Overwritten: this process fell a whole ring behind
                    $this->next = $head - $ring + 1;
                    $gap();
                    Swerve::log()->warning('Pub/sub: this worker fell more than the ring of {ring} messages behind, and lost messages (--pubsub-ring)', ['ring' => $ring]);
                    continue;
                }
                ++$this->next;
                $deliver($entry[0], $entry[1], $entry[2], $entry[3]);
                if ($this->stopped) {
                    return; // its process stopped reading during the delivery
                }
                $behind = $head - $this->next;
                if ($behind >= $ring * 3 >> 2 && !$this->warned) {
                    $this->warned = true;
                    Swerve::log()->warning('Pub/sub: this worker is {behind} messages behind, close to the ring of {ring} (--pubsub-ring)', ['behind' => $behind, 'ring' => $ring]);
                } elseif ($behind < $ring >> 1) {
                    $this->warned = false;
                }
            }
            try {
                phasync::readable($this->own, null === $missing ? \PHP_FLOAT_MAX : self::ABANDONED);
            } catch (TimeoutException) {
            } catch (CancelledException) {
                return; // stop()
            }
            while ('' !== (string) @\stream_socket_recvfrom($this->own, 64)) {
                // the doorbells rung meanwhile: one read of the log answers them all
            }
        }
    }

    private static function ringSize(): int
    {
        static $size = null;

        return $size ??= (int) (\getenv('SWERVE_PUBSUB_RING') ?: self::DEFAULT_RING);
    }

    /**
     * Ring the doorbell of process $pid: it reads the log. A doorbell is connected once and kept;
     * one that fails is bound anew (the process stopped reading, then started again), full (it
     * rings already), or gone: connected again, and rung once more.
     */
    private static function ringBell(int $pid, string $address, bool $again = true): void
    {
        if (!isset(self::$doorbells[$pid])) {
            $bell = @\stream_socket_client("udg://$address");
            if (false === $bell) {
                return; // gone: the master forgets it as it reaps it
            }
            \stream_set_blocking($bell, false);
            self::$doorbells[$pid] = $bell;
        }
        if (false !== @\fwrite(self::$doorbells[$pid], "\1")) {
            return;
        }
        \error_clear_last();
        if ($again) {
            \fclose(self::$doorbells[$pid]);
            unset(self::$doorbells[$pid]);
            self::ringBell($pid, $address, false);
        }
    }

    private static function head(): int
    {
        return (int) \apcu_fetch(self::HEAD);
    }

    private static function address(int $pid): string
    {
        $name = 'swerve-' . \posix_getppid() . '-' . $pid;

        return 'Linux' === \PHP_OS_FAMILY ? "\0$name" : \sys_get_temp_dir() . "/$name.sock";
    }

    private static function register(int $pid, string $address): void
    {
        self::update(static fn (array $readers) => [$pid => $address] + $readers);
    }

    private static function unregister(int $pid): void
    {
        self::update(static function (array $readers) use ($pid) {
            unset($readers[$pid]);

            return $readers;
        });
    }

    /** Change the readers under a lock: subscriptions start and end rarely. */
    private static function update(\Closure $change): void
    {
        while (!\apcu_add(self::READERS . ':lock', 1, 1)) {
            phasync::sleep(0.0001);
        }
        try {
            \apcu_store(self::READERS, $change(\apcu_fetch(self::READERS) ?: []));
        } finally {
            \apcu_delete(self::READERS . ':lock');
        }
    }
}
