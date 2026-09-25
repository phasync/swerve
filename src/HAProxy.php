<?php

namespace Swerve;

use phasync\Process\Process;
use phasync\Process\ProcessInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs HAProxy as swerve's HTTP front, as a child process of the master process.
 *
 * HAProxy passes requests to the workers over FastCGI, multiplexing many requests over
 * each connection: one read on a worker's socket brings in many requests. HAProxy keeps
 * separate connections per thread, so it runs with few threads; one moves a lot of
 * traffic. The workers listen on fixed per-slot socket paths, so the configuration stays
 * the same when a worker is restarted.
 */
final class HAProxy
{
    private ?ProcessInterface $process = null;
    private ?string $configFile = null;

    /**
     * @param string[] $listen  HTTP addresses to listen on, such as '127.0.0.1:8080'
     * @param string[] $workers FastCGI Unix socket paths of the workers
     * @param int      $threads HAProxy threads
     */
    public function __construct(
        private array $listen,
        private array $workers,
        private LoggerInterface $logger,
        private int $threads = 1,
    ) {
    }

    /**
     * The HAProxy executable: $SWERVE_HAPROXY, or haproxy found in PATH or an sbin directory.
     */
    public static function findBinary(): ?string
    {
        $binary = \getenv('SWERVE_HAPROXY');
        if (\is_string($binary) && '' !== $binary) {
            return $binary;
        }
        $dirs = \array_merge(\explode(\PATH_SEPARATOR, (string) \getenv('PATH')), ['/usr/local/sbin', '/usr/sbin']);
        foreach ($dirs as $dir) {
            if ('' !== $dir && \is_executable("$dir/haproxy")) {
                return "$dir/haproxy";
            }
        }

        return null;
    }

    public function config(): string
    {
        $binds = '';
        foreach ($this->listen as $address) {
            $binds .= "    bind $address\n";
        }
        $servers = '';
        foreach ($this->workers as $slot => $path) {
            $servers .= "    server worker$slot unix@$path proto fcgi\n";
        }

        return <<<CONFIG
            global
                nbthread {$this->threads}
                maxconn 65536

            defaults
                mode http
                timeout connect 5s
                timeout client 60s
                timeout server 60s
                timeout http-keep-alive 60s

            frontend swerve
            {$binds}    default_backend workers

            backend workers
                balance roundrobin
                http-reuse always
                use-fcgi-app swerve
            {$servers}
            fcgi-app swerve
                docroot /
                option mpxs-conns
                option keep-conn
                option max-reqs 1000
                no option get-values

            CONFIG;
    }

    /**
     * Start HAProxy. Its output goes to the logger.
     *
     * @param \Closure(): void $onExit Called if HAProxy exits while running
     *
     * @throws \RuntimeException if HAProxy is not installed
     */
    public function start(\Closure $onExit): void
    {
        $binary = self::findBinary()
            ?? throw new \RuntimeException('HAProxy not found: install haproxy, or set SWERVE_HAPROXY to its path');

        $this->configFile = \tempnam(\sys_get_temp_dir(), 'swerve-haproxy-');
        \file_put_contents($this->configFile, $this->config());
        // -db: stay in the foreground, so it is a child that stops with swerve
        $this->process = Process::run($binary, ['-db', '-f', $this->configFile]);

        \phasync::go(function () use ($onExit) {
            $buffer = '';
            while (false !== ($chunk = $this->process->read(ProcessInterface::STDERR)) && '' !== $chunk) {
                $buffer .= $chunk;
                while (false !== ($end = \strpos($buffer, "\n"))) {
                    $this->logger->notice('haproxy: {line}', ['line' => \substr($buffer, 0, $end)]);
                    $buffer = \substr($buffer, $end + 1);
                }
            }
            if (null !== $this->process) {
                $onExit();
            }
        });
    }

    public function stop(): void
    {
        $process       = $this->process;
        $this->process = null;
        $process?->stop();
        if (null !== $this->configFile && \is_file($this->configFile)) {
            \unlink($this->configFile);
        }
        $this->configFile = null;
    }
}
