<?php

namespace Swerve\Util;

use phasync;
use phasync\SubscriberInterface;
use phasync\TimeoutException;
use Swerve\Cache;
use Swerve\Subscription;

/**
 * The log behind OrderedChannel: per topic, files in a
 * directory the master made before it forked the workers (see directory()), appended to by every
 * worker with one write() on an O_APPEND descriptor. The kernel serializes appends to a file, so
 * the order of the frames in it is the one order every ordered subscriber sees. A frame is the
 * message's length (4 bytes, big endian) and its JSON; the topic is implied by the file's name.
 * Nothing that can be reaped holds a message: once appended, it is in the file.
 *
 * Who appends and who reads is the master's business, as for plain topics. Each worker with
 * ordered subscribers has a reader per topic, a service coroutine that subscribes to the topic's
 * wake topic, "\0w<hash>". A publisher appends only while the master has a subscriber on that
 * wake topic, and then sends the workers subscribed a one-datagram wake-up (best effort, as any
 * message) so that readers need not poll; a reader also looks every TICK seconds. Messages go to
 * the subscribers of the message topic, "\0o<hash>", as Topics::deliver() does for any topic.
 *
 * A reader starts at the end of the topic's current segment once the master has acknowledged its
 * subscription: a writer checks the master's bitmap before it reads the clock, so what is
 * appended after the acknowledgement goes to that segment or a later one. It reads to the end of
 * the file on every wake-up, parses the complete frames and keeps an incomplete tail for the next
 * read (a large append is visible half-written for a moment).
 *
 * Rotation bounds the disk: a segment is "<hash>.<bucket>", the bucket being the monotonic clock
 * (hrtime(): the same in every process, and it never steps back) divided by $segment. A writer
 * appends to the file of the bucket it reads at that moment. A reader leaves a segment only when
 * it is at its end of file and the clock is more than $grace past the end of the bucket, which
 * covers writers that straddle the boundary. The one inversion left: a writer descheduled for
 * longer than $grace between reading the clock and appending, whose message then lands in a
 * segment its readers have left and is not seen by them. Segments more than RETAIN buckets old
 * are unlinked by whichever worker's reader notices, those of other topics included. A reader
 * that finds the segment it is in gone (its worker's event loop was blocked for that long) has
 * lost messages: its subscribers get a SubscriberLagException, see lost().
 *
 * Without a master (swerve embedded) there is no log: messages are delivered to this process's
 * subscribers at once.
 *
 * @internal
 */
final class OrderedLog
{
    /** Prefixes of the internal topics: the messages to subscribers, and the wake-ups. */
    private const MESSAGES = "\0o";
    private const WAKE     = "\0w";
    /** A segment is unlinked when it is more than this many buckets old. */
    private const RETAIN = 3;
    /** Seconds between a reader's looks when nothing woke it: a dropped wake-up costs this much. */
    private const TICK = 1.0;
    /** Bytes a reader reads at a time. */
    private const CHUNK = 1 << 18;

    /** Seconds of a segment, and of grace before a reader leaves one. Only tests change these, in the workers' application. */
    public static float $segment = 10.0;
    public static float $grace   = 1.0;

    private static ?string $directory = null;
    /** @var array<string, resource> this worker's append handles, by topic hash */
    private static array $files = [];
    /** @var array<string, int> the bucket of each handle in $files */
    private static array $buckets = [];
    /** The newest bucket that has closed the handles of older ones. */
    private static int $closedBefore = 0;
    private static float $nextSweep  = 0.0;
    /** @var array<string, self> a reader per topic hash while this worker has ordered subscribers */
    private static array $readers = [];

    private ?SubscriberInterface $wake = null;
    private bool $ready                = false;
    private int $bucket                = 0;
    /** @var resource|null */
    private $file = null;
    /** What was read of the segment and is not a complete frame yet. */
    private string $buffer = '';

    private function __construct(private readonly string $id)
    {
    }

    /**
     * The directory of the logs, see System::tempDirectory(). The master creates it before it
     * forks, so that the workers inherit it.
     */
    public static function directory(): string
    {
        return self::$directory ??= System::tempDirectory('swerve-ordered');
    }

    public static function publish(string $topic, mixed $message): void
    {
        $json = Topics::encode($topic, $message);
        $id   = \hash('sha256', $topic);
        if (null === Topics::$toMaster) {
            Topics::deliver(self::MESSAGES . $id, $json);

            return;
        }
        $wake = self::WAKE . $id;
        if ('' === $bitmap = Topics::bitmap('OrderedChannel::write', $wake)) {
            return; // no ordered subscriber anywhere: nobody appends
        }
        $bucket = self::bucket(self::now());
        if ((self::$buckets[$id] ?? -1) !== $bucket) {
            if ($bucket !== self::$closedBefore) {
                foreach (self::$buckets as $other => $older) {
                    if ($older < $bucket) {
                        \fclose(self::$files[$other]);
                        unset(self::$files[$other], self::$buckets[$other]);
                    }
                }
                self::$closedBefore = $bucket;
            }
            self::$files[$id]   = \fopen(self::path($id, $bucket), 'a');
            self::$buckets[$id] = $bucket;
            \stream_set_write_buffer(self::$files[$id], 0); // one fwrite() is one write(): a frame is never interleaved
        }
        $frame = \pack('N', \strlen($json)) . $json;
        if (\strlen($frame) !== $written = \fwrite(self::$files[$id], $frame)) {
            throw new \RuntimeException('Appending to the ordered log wrote ' . \var_export($written, true) . ' of ' . \strlen($frame) . ' bytes');
        }
        Topics::fanOut($wake, '1', $bitmap);
    }

    public static function subscribe(string $topic, float $maxLag, ?float $heartbeat): Subscription
    {
        if ('' === $topic || \strlen($topic) > Topics::MAX_TOPIC) {
            throw new \InvalidArgumentException('A topic is 1 to ' . Topics::MAX_TOPIC . ' bytes, not ' . \strlen($topic));
        }
        if (null !== Topics::$toMaster && !Cache::$listening) {
            Cache::awaitListening('OrderedChannel::subscribe()');
        }
        $id           = \hash('sha256', $topic);
        $subscription = new Subscription(self::MESSAGES . $id, $maxLag, $heartbeat);
        if (null === Topics::$toMaster || Topics::$closed) {
            return $subscription;
        }
        if (!isset(self::$readers[$id])) {
            self::$readers[$id] = new self($id);
            phasync::service(self::$readers[$id]->run(...));
        }
        // Returns when the reader has started where the master's acknowledgement put it
        $reader = self::$readers[$id];
        while (!$reader->ready) {
            phasync::awaitFlag($reader);
        }

        return $subscription;
    }

    /** The reader: until this worker has no more ordered subscribers of the topic, or drains. */
    private function run(): void
    {
        $messages     = self::MESSAGES . $this->id;
        $wake         = self::WAKE . $this->id;
        $this->wake   = Topics::subscribe($wake); // returns when the master has it on record
        $this->bucket = self::bucket(self::now());
        if (false !== $file = @\fopen(self::path($this->id, $this->bucket), 'r')) {
            \fseek($file, 0, \SEEK_END);
            $this->file = $file;
        }
        \error_clear_last();
        $this->ready = true;
        phasync::raiseFlag($this);
        try {
            while (true) {
                try {
                    $this->wake->read(self::TICK, $eof);
                } catch (TimeoutException) {
                    $eof = false;
                }
                // The wake topic ends at a drain; the messages' when its last subscriber is gone
                if ($eof || !Topics::has($messages)) {
                    return;
                }
                $this->pump($messages);
            }
        } finally {
            unset(self::$readers[$this->id]);
            Topics::leave($wake);
        }
    }

    /** Read to the end of the segment, and on to the next ones that the clock says are over. */
    private function pump(string $messages): void
    {
        while (true) {
            $now = self::now();
            // Decided before the last read of the segment: nothing is appended to it after the grace
            $over = $this->bucket < self::bucket($now - self::$grace);
            if (null === $this->file && !($this->file = @\fopen(self::path($this->id, $this->bucket), 'r'))) {
                \error_clear_last();
                $this->file = null;
                if ($this->bucket < self::bucket($now) - self::RETAIN) {
                    $this->lost($messages, $now);
                    continue;
                }
            }
            while (null !== $this->file && '' !== $data = (string) \fread($this->file, self::CHUNK)) {
                $this->buffer .= $data;
                $at     = 0;
                $length = \strlen($this->buffer);
                while ($length - $at >= 4) {
                    $size = \unpack('N', $this->buffer, $at)[1];
                    if ($length - $at - 4 < $size) {
                        break;
                    }
                    Topics::deliver($messages, \substr($this->buffer, $at + 4, $size));
                    $at += 4 + $size;
                }
                $this->buffer = \substr($this->buffer, $at);
            }
            if (!$over) {
                break;
            }
            if ('' !== $this->buffer) {
                throw new \RuntimeException('An ordered log segment ended inside a message');
            }
            if (null !== $this->file) {
                \fclose($this->file);
                $this->file = null;
            }
            ++$this->bucket;
        }
        if ($now >= self::$nextSweep) {
            self::sweep($now);
        }
    }

    /**
     * The segment this reader is in was unlinked before it read it: messages are lost. Its
     * subscribers get a SubscriberLagException, the one a subscriber that falls too far behind
     * gets: a message that arrived at the beginning of time, which stands after everything
     * delivered. The reader goes on in the current segment.
     */
    private function lost(string $messages, float $now): void
    {
        Topics::deliver($messages, 'null', \PHP_INT_MIN);
        $this->bucket = self::bucket($now);
        $this->buffer = '';
    }

    private static function sweep(float $now): void
    {
        self::$nextSweep = $now + self::$segment;
        $horizon         = self::bucket($now) - self::RETAIN;
        foreach (\glob(self::directory() . '/*.*') as $file) {
            if ((int) \substr($file, \strrpos($file, '.') + 1) < $horizon) {
                @\unlink($file); // another worker may have, too
            }
        }
        \error_clear_last();
    }

    private static function path(string $id, int $bucket): string
    {
        return self::directory() . "/$id.$bucket";
    }

    private static function now(): float
    {
        return \hrtime(true) / 1e9;
    }

    private static function bucket(float $now): int
    {
        return (int) \floor($now / self::$segment);
    }
}
