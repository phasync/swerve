<?php

namespace Swerve\Util;

use Charm\Terminal;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;

class Logger implements LoggerInterface
{
    use LoggerTrait;

    /** Levels named on the line, padded alike. */
    private const LABELS = [
        LogLevel::WARNING   => 'warning  ',
        LogLevel::ERROR     => 'error    ',
        LogLevel::CRITICAL  => 'critical ',
        LogLevel::ALERT     => 'alert    ',
        LogLevel::EMERGENCY => 'emergency',
    ];

    private const COLORS = [
        LogLevel::DEBUG => '<!silverBG black>',
        LogLevel::INFO => '<!tealBG black>',
        LogLevel::NOTICE => '<!fuchsia black>',
        LogLevel::WARNING => '<!yellow>',
        LogLevel::ERROR => '<!red>',
        LogLevel::CRITICAL => '<!redBG white>',
        LogLevel::ALERT => '<!redBG white>',
        LogLevel::EMERGENCY => '<!redBG white>',
    ];

    private string $source;
    private array $logLevels = [
        LogLevel::DEBUG => true,
        LogLevel::INFO => true,
        LogLevel::NOTICE => true,
        LogLevel::WARNING => true,
        LogLevel::ERROR => true,
        LogLevel::CRITICAL => true,
        LogLevel::ALERT => true,
        LogLevel::EMERGENCY => true,
    ];
    /** @var resource */
    private $stream;
    /** Renders the markup of the line's prefix, for $stream. */
    private Terminal $term;

    /**
     * @param resource    $stream
     * @param string|null $path   the file $stream writes to, if any: see reopen()
     */
    /** The second the time prefix was made for, and the prefix, see prefix(). */
    private int $second = -1;
    private string $time = '';

    /**
     * @param string      $source the column after the time: the worker's slot, blank for the master
     * @param bool        $access whether request() logs
     */
    public function __construct($stream, string $source = '', string $logLevel = LogLevel::DEBUG, private readonly ?string $path = null, public readonly bool $access = false)
    {
        $this->stream = $stream;
        $this->term   = new Terminal($stream);
        $this->source = $source;
        foreach ($this->logLevels as $level => $state) {
            if ($logLevel === $level) {
                break;
            }
            $this->logLevels[$level] = false;
        }
    }

    /** The same log, for a worker: its slot right-aligned in the column the master leaves blank. */
    public function withSource(string $source): Logger
    {
        $c         = clone $this;
        $c->source = \str_pad($source, \strlen($this->source), ' ', \STR_PAD_LEFT);

        return $c;
    }

    /**
     * Write to the file at $path anew, after log rotation renamed the one open. Processes forked
     * later inherit the new one; those forked before keep writing to the old one until they exit.
     */
    public function reopen(): void
    {
        if (null === $this->path) {
            return;
        }
        $file = @\fopen($this->path, 'a');
        if (false === $file) {
            $this->warning("Can't reopen the log file {path}, so logging goes on to the file open before: {error}", ['path' => $this->path, 'error' => \error_get_last()['message'] ?? 'unknown error']);

            return;
        }
        $this->stream = $file;
        $this->term   = new Terminal($file);
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (empty($this->logLevels[$level])) {
            return;
        }
        // The message and its values are written as they are, never read as markup: a client's
        // request target or an exception's message must not change the line ('<!!>' vanishes)
        // nor reach a terminal as escape sequences ('<!clear>'). Control characters are escaped
        // for the same reason.
        // Notices and below are just the message: most lines are those
        $line = $this->prefix() . (isset(self::LABELS[$level]) ? $this->markup(self::COLORS[$level] . self::LABELS[$level] . '<!>') . ' ' : '');
        foreach (\preg_split('/(\{[^{}\s]+\})/', \rtrim((string) $message), -1, \PREG_SPLIT_DELIM_CAPTURE) as $i => $part) {
            $key = \substr($part, 1, -1);
            $val = $context[$key] ?? null;
            if ($i % 2 && \array_key_exists($key, $context) && !\is_array($val) && (!\is_object($val) || \method_exists($val, '__toString'))) {
                $line .= $this->markup('<!underline>').self::text((string) $val).$this->markup('<!>');
            } else {
                $line .= self::text($part);
            }
        }
        // A line that can't be written (a full disk, a log reader gone) is lost, without a warning:
        // the application's error handler may turn it into an exception, thrown from wherever
        // swerve logs, such as a drain or the 500 for another exception
        @\fwrite($this->stream, $line."\n");
    }

    /**
     * One request answered: `GET /path 200 1.2ms`, when the access log is on.
     */
    public function request(string $method, string $target, int $status, float $seconds): void
    {
        if ($this->access) {
            @\fwrite($this->stream, $this->prefix() . self::text("$method $target $status ") . self::duration($seconds) . "\n");
        }
    }

    /** The local time to hundredths of a second, and the source column. */
    private function prefix(): string
    {
        $now = \microtime(true);
        if ((int) $now !== $this->second) {
            $this->second = (int) $now;
            $this->time   = \date('Y-m-d H:i:s', $this->second);
        }

        return $this->markup('<!white>' . $this->time . \sprintf('.%02d', (int) (($now - $this->second) * 100)) . '<!>') . ' ' . $this->source . ' ';
    }

    private static function duration(float $seconds): string
    {
        return $seconds < 1 ? \sprintf('%.1fms', $seconds * 1000) : \sprintf('%.2fs', $seconds);
    }

    private function markup(string $markup): string
    {
        return $this->term->isTTY() ? $this->term->process($markup) : Terminal::strip($markup);
    }

    /** Control characters other than newline and tab, escaped as in C: "\033[2J". */
    private static function text(string $text): string
    {
        return \addcslashes($text, "\0..\x08\x0B..\x1F\x7F");
    }
}
