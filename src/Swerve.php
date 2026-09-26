<?php

namespace Swerve;

use phasync\Context\ContextInterface;
use phasync\SelectableInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use swerve\ServerInterface;
use Swerve\Util\Topics;

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

    /** stop() was called: run() returns once the servers are drained. */
    private bool $stopping = false;

    private ?LoggerInterface $logger = null;

    /** The application run() serves. */
    private ?SwerveInterface $app = null;

    public function __construct(LoggerInterface $logger)
    {
        $this->pendingConnections = new \SplQueue();
        $this->logger = $logger;
        self::$log    = $logger;
        $this->logger->debug('Swerve constructed');
    }

    public function isReady(): bool
    {
        return $this->running && $this->pendingConnections->isEmpty();
    }

    public function await(float $timeout = \PHP_FLOAT_MAX): void
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

    /**
     * Serve until stopped. Returns after stop(), once every request and connection ended.
     *
     * @param \Closure|null         $opened  called once every server listens
     * @param ContextInterface|null $context the phasync context of the coroutines serving requests
     */
    public function run(SwerveInterface $app, ?\Closure $opened = null, ?ContextInterface $context = null): void
    {
        if ($this->running) {
            throw new \RuntimeException('Already running');
        }
        $this->app = $app;
        \phasync::run(function () use ($app, $opened) {
            try {
                $this->running = true;
                foreach ($this->modules as $module) {
                    if ($module instanceof ServerInterface) {
                        $this->logger->info('Opening module {module}', ['module' => $module->getName()]);
                        $module->open($this->addConnection(...));
                    }
                }

                if ($opened) {
                    $opened();
                }

                while (!$this->stopping) {
                    while (!$this->stopping && $this->pendingConnections->isEmpty()) {
                        \phasync::awaitFlag($this->pendingConnections, \PHP_FLOAT_MAX);
                    }
                    while (!$this->pendingConnections->isEmpty()) {
                        $connection = $this->pendingConnections->dequeue();
                        \phasync::go(function () use ($app, $connection) {
                            $this->handle($app, $connection);
                        });
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->critical($e);
            } finally {
                $this->running = false;
                // Drained servers close themselves as their connections end
                if (!$this->stopping) {
                    foreach ($this->modules as $module) {
                        if ($module instanceof ServerInterface) {
                            $this->logger->info('Closing module {module}', ['module' => $module->getName()]);
                            $module->close();
                        }
                    }
                }
            }
        }, [], $context);
    }

    /**
     * Stop accepting, let the requests in flight finish, and make run() return once they did.
     */
    public function stop(): void
    {
        $this->stopping = true;
        foreach ($this->modules as $module) {
            if ($module instanceof ServerInterface) {
                $module->drain();
            }
        }
        \phasync::raiseFlag($this->pendingConnections);
    }

    /**
     * An application that throws gets a 500 when nothing was sent yet, and the request is
     * ended either way, so the front server is never left waiting for it.
     */
    private function handle(SwerveInterface $app, ConnectionInterface $connection): void
    {
        try {
            $app->handleConnection($connection);
        } catch (\Throwable $e) {
            // Not waiting for a request head that never came
            $request = $connection->getState() > Connection::STATE_REQUEST_HEAD ? $connection->getRequestMethod() . ' ' . $connection->getRequestTarget() : 'A request';
            $this->logger->error('{request} failed: {exception}', ['request' => $request, 'exception' => $e]);
            if (Connection::STATE_RESPONSE_HEAD === $connection->getState()) {
                $connection->sendHead(['Content-Type: text/plain'], 500);
                $connection->write('Internal Server Error');
            }
            if (Connection::STATE_BODY === $connection->getState()) {
                $connection->end();
            }
        }
    }

    /**
     * Register a newly received request with Swerve, so that the request can
     * be processed by a runner. While stopping, run() no longer takes requests from the queue,
     * yet one can still arrive on a connection that stays open for the requests in flight
     * (multiplexed, or kept); the front server sent it, so it is served.
     */
    private function addConnection(ConnectionInterface $connection): void
    {
        if ($this->stopping) {
            \phasync::go(function () use ($connection) {
                $this->handle($this->app, $connection);
            });

            return;
        }
        $this->pendingConnections->enqueue($connection);
        \phasync::raiseFlag($this->pendingConnections);
    }

    /** The log of this process, see log(). */
    private static ?LoggerInterface $log = null;

    /**
     * The log of this process: swerve's own, in its format (the time, this worker's slot),
     * wherever swerve logs (the terminal, or --log's file); a NullLogger with -q. Give it to
     * the libraries that take a PSR-3 logger, such as Slim's error middleware:
     *
     *     $app->addErrorMiddleware(true, true, false, Swerve::log());
     *
     * Without swerve's command line (swerve embedded), the logger of the last Swerve created.
     */
    public static function log(): LoggerInterface
    {
        return self::$log ??= new NullLogger();
    }

    /**
     * @internal set by bin/swerve and the Swerve constructor
     */
    public static function setLog(LoggerInterface $log): void
    {
        self::$log = $log;
    }

    /**
     * Send a message to every subscriber of $topic, in every worker process of this swerve,
     * this one included. Returns once the message is on its way, not once delivered.
     *
     * Delivery is at most once, to the subscriptions that exist when the message reaches their
     * worker: there is no history, and a worker starting later (after a reload, a recycle, a
     * crash) sees nothing sent before. Every subscriber sees the messages of a topic in the
     * same order. Swerve embedded without its master process delivers in this process only.
     *
     * @param string $topic   1 to 255 bytes
     * @param string $message at most 1 MiB
     *
     * @throws \InvalidArgumentException for a topic or message outside those sizes
     */
    public static function publish(string $topic, string $message): void
    {
        Topics::publish($topic, $message);
    }

    /**
     * Receive what is published to $topic from now on:
     *
     *     foreach (Swerve::subscribe('chat') as $message) { ... }
     *
     * The subscription ends when its last reference goes, see Subscription. One falling more
     * than $maxLag seconds behind gets a SubscriberLagException from the loop.
     */
    public static function subscribe(string $topic, float $maxLag = 30.0): Subscription
    {
        return new Subscription($topic, $maxLag);
    }

    /** The installed version, as Composer knows it: 0.1.0-alpha3, or dev-main in a checkout. */
    public static function getVersion(): string
    {
        return \Composer\InstalledVersions::getPrettyVersion('phasync/swerve') ?? 'unknown';
    }
}
