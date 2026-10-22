<?php

namespace Swerve\Util\Classic;

use Psr\Log\LoggerInterface;

/**
 * ext-phasync's request server, as Swerve's master runs it for a Classic application: one for
 * every worker, so its children share one opcache. A fresh `php` process (the server takes control
 * at PHP startup), never a fork of the master. Its first process only accepts, forks one child per
 * connection and reaps them, so it practically never dies; when it does, it is started again.
 *
 * The master starts it when the first worker reports a {@see \Swerve\ClassicMode} (the master never
 * loads the application), and stops it once the workers are drained, on shutdown and reload.
 *
 * @internal
 */
final class ServerProcess
{
    /** Message from a worker: serialize([public root, router]). */
    public const TOPIC = "\0classic";

    /** The server's socket: chosen by the master before it forks the workers, which inherit it. */
    public static ?string $socket = null;

    /** @var resource|null */
    private $process = null;

    private ?int $pid = null;

    /** @var array{string, string}|null the public root and router it serves */
    private ?array $serving = null;

    private float $started = 0.0;

    public function __construct(private readonly LoggerInterface $logger, private readonly int $maxChildren)
    {
        self::$socket = \sys_get_temp_dir() . '/swerve-' . \posix_getpid() . '-classic.sock';
    }

    /**
     * A worker loaded a Classic application: start the server for it, unless it runs.
     */
    public function request(string $message): void
    {
        [$root, $router] = \unserialize($message, ['allowed_classes' => false]);
        if (null !== $this->serving) {
            if ([$root, $router] !== $this->serving) {
                $this->logger->error('A worker loaded a Classic application with public root {root} and router {router}, '
                    . 'but the request server runs {old_root} with {old_router}: it serves on as it is until a reload', [
                        'root' => $root, 'router' => $router, 'old_root' => $this->serving[0], 'old_router' => $this->serving[1],
                    ]);
            }

            return;
        }
        $this->serving = [$root, $router];
        $this->start();
    }

    /** Whether the master reaped this server: it is started again. */
    public function exited(int $pid, int $status, bool $stopping): bool
    {
        if ($pid !== $this->pid) {
            return false;
        }
        \proc_close($this->process);
        $this->process = null;
        $this->pid     = null;
        if (!$stopping) {
            $this->logger->critical('The request server {pid} died ({how}) after {s} s; starting it again', [
                'pid' => $pid, 'how' => \pcntl_wifsignaled($status) ? 'signal ' . \pcntl_wtermsig($status) : 'exit ' . \pcntl_wexitstatus($status),
                's'   => \round(\microtime(true) - $this->started, 1),
            ]);
            $this->start();
        }

        return true;
    }

    /**
     * Stop it, the workers being drained: SIGTERM, then SIGKILL after `$grace` seconds.
     */
    public function stop(float $grace): void
    {
        if (null === $this->pid) {
            return;
        }
        \posix_kill($this->pid, \SIGTERM);
        $deadline = \microtime(true) + $grace;
        while (0 === \pcntl_waitpid($this->pid, $status, \WNOHANG)) {
            if (\microtime(true) >= $deadline) {
                \posix_kill($this->pid, \SIGKILL);
                \pcntl_waitpid($this->pid, $status);
                break;
            }
            \usleep(10_000);
        }
        \proc_close($this->process);
        $this->process = null;
        $this->pid     = null;
        $this->serving = null;
        @\unlink(self::$socket);
    }

    private function start(): void
    {
        [$root, $router] = $this->serving;
        @\unlink(self::$socket);
        $args = [\PHP_BINARY, ...self::inheritedFlags(),
            '-d', 'phasync.serve=unix:' . self::$socket,
            '-d', "phasync.serve_root=$root",
            '-d', "phasync.serve_router=$router",
            '-d', "phasync.serve_max_children={$this->maxChildren}",
        ];
        $process = \proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => \STDOUT, 2 => \STDERR], $pipes, $root);
        if (false === $process) {
            throw new \RuntimeException('The request server could not be started');
        }
        $this->process = $process;
        $this->pid     = \proc_get_status($process)['pid'];
        $this->started = \microtime(true);
        $this->logger->info('Started the request server {pid} for {root} (router {router}), up to {n} processes', [
            'pid' => $this->pid, 'root' => $root, 'router' => $router, 'n' => $this->maxChildren,
        ]);
    }

    /**
     * The `-d` flags of this process that the server needs too: the extensions it loaded beyond
     * php.ini (ext-phasync itself, opcache), and opcache's and phasync's settings. The rest comes from
     * php.ini, as for PHP-FPM: not Swerve's own settings for its long-running workers.
     *
     * @return list<string>
     */
    private static function inheritedFlags(): array
    {
        $argv  = \explode("\0", \rtrim((string) \file_get_contents('/proc/self/cmdline'), "\0"));
        $flags = [];
        foreach ($argv as $i => $arg) {
            $setting = '-d' === $arg ? ($argv[$i + 1] ?? '') : (\str_starts_with($arg, '-d') ? \substr($arg, 2) : null);
            if (null !== $setting && \preg_match('/^(?:extension|zend_extension|opcache\.[a-z_]+|phasync\.[a-z_]+)=/', $setting) && !\str_starts_with($setting, 'phasync.serve')) {
                \array_push($flags, '-d', $setting);
            }
        }

        return $flags;
    }
}
