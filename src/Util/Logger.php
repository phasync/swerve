<?php

namespace Swerve\Util;

use phasync\Util\Console;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;

class Logger implements LoggerInterface
{
    use LoggerTrait;

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
    private Console $console;

    /**
     * @param resource    $stream
     * @param string|null $path   the file $stream writes to, if any: see reopen()
     */

    /**
     * @param string      $source the column after the time: the worker's slot, blank for the master
     * @param bool        $access whether request() logs
     */
    public function __construct($stream, string $source = '', string $logLevel = LogLevel::DEBUG, private readonly ?string $path = null, public readonly bool $access = false)
    {
        $this->stream = $stream;
        $this->console = new Console($stream);
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
        $this->console = new Console($file);
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (!empty($this->logLevels[$level])) {
            $this->console->log($level, $message, $context, $this->source);
        }
    }

    /**
     * One request answered: `GET /path 200 1.2ms`, when the access log is on.
     */
    public function request(string $method, string $target, int $status, float $seconds): void
    {
        if ($this->access) {
            $this->console->log(LogLevel::INFO, "$method $target $status " . self::duration($seconds), [], $this->source);
        }
    }

    private static function duration(float $seconds): string
    {
        return $seconds < 1 ? \sprintf('%.1fms', $seconds * 1000) : \sprintf('%.2fs', $seconds);
    }

}
