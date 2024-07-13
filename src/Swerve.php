<?php

namespace Swerve;

use phasync\SelectableInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use swerve\ServerInterface;

final class Swerve implements SelectableInterface, LoggerAwareInterface
{
    /**
     * @var \SplQueue<ConnectionInterface>
     */
    private \SplQueue $pendingConnections;

    /**
     * Swerve modules.
     *
     * @var array<int,ModuleInterface>
     */
    private array $modules = [];

    private bool $running = false;

    private ?LoggerInterface $logger = null;

    public function __construct(LoggerInterface $logger)
    {
        $this->pendingConnections = new \SplQueue();
        $this->logger = $logger;
        $this->logger->debug('Swerve constructed');
    }

    public function isReady(): bool
    {
        return $this->running && $this->pendingConnections->isEmpty();
    }

    public function await(): void
    {
        while (!$this->isReady()) {
            \phasync::awaitFlag($this->pendingConnections);
        }
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Add a module to Swerve.
     *
     * @throws \RuntimeException
     */
    public function add(ModuleInterface $module): void
    {
        $this->logger->debug('Attaching module {module}', ['module' => $module->getName()]);
        $id = \spl_object_id($module);
        if (isset($this->modules[$id])) {
            throw new \RuntimeException('Module '.$module->getName().' already attached');
        }
        $this->modules[$id] = $module;
        $module->attach($this);
        $this->logger->log(LogLevel::INFO, '{module} attached', ['module' => $module->getName()]);

        if ($this->running && $module instanceof ServerInterface) {
            $module->open($this->addConnection(...));
        }
    }

    /**
     * Remove a module from Swerve.
     *
     * @throws \RuntimeException
     */
    public function remove(ModuleInterface $module): void
    {
        $this->logger->debug('Detaching module {module}', ['module' => $module->getName()]);
        $id = \spl_object_id($module);
        if (!isset($this->modules[$id])) {
            throw new \RuntimeException('Module '.$module->getName().' is not attached');
        }

        if ($this->running && $module instanceof ServerInterface) {
            $module->close();
        }

        $module->detach();
        unset($this->modules[$id]);
        $this->logger->log(LogLevel::INFO, 'Detached module {module}', ['module' => $module->getName()]);
    }

    public function run(SwerveInterface $app): void
    {
        if ($this->running) {
            throw new \RuntimeException('Already running');
        }
        \phasync::run(function () use ($app) {
            try {
                $this->running = true;
                foreach ($this->modules as $module) {
                    if ($module instanceof ServerInterface) {
                        $this->logger->info('Opening module {module}', ['module' => $module->getName()]);
                        $module->open($this->addConnection(...));
                    }
                }

                while (true) {
                    while ($this->pendingConnections->isEmpty()) {
                        \phasync::awaitFlag($this->pendingConnections, \PHP_FLOAT_MAX);
                    }
                    $connection = $this->pendingConnections->dequeue();
                    \phasync::go(function () use ($app, $connection) {
                        $app->handleConnection($connection);
                    });
                }
            } catch (\Throwable $e) {
                $this->logger->critical($e);
            } finally {
                $this->running = false;
                foreach ($this->modules as $module) {
                    if ($module instanceof ServerInterface) {
                        $this->logger->info('Closing module {module}', ['module' => $module->getName()]);
                        $module->close();
                    }
                }
            }
        });
    }

    /**
     * Register a newly received request with Swerve, so that the request can
     * be processed by a runner.
     */
    private function addConnection(ConnectionInterface $connection): void
    {
        $this->pendingConnections->enqueue($connection);
        \phasync::raiseFlag($this->pendingConnections);
    }

    public static function getVersion(): string
    {
        return '1.0';
    }
}
