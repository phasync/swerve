<?php

namespace Swerve\Lot;

use phasync;
use phasync\CancelledException;
use phasync\ext;
use phasync\ShutdownException;
use phasync\Net\StreamDuplex;
use Serializor\Codec;
use Swerve\Http\SapiRequest;
use Swerve\StopReason;
use Swerve\Swerve;
use Swerve\Util\Topics;
use Swerve\WorkerStoppingException;
use Swerve\WebSocket;

/**
 * A process that serves the WebSockets moved with one bootstrap file: Swerve::upgrade() with a
 * bootstrap answers the handshake in the worker and moves the connection here, with its handler
 * and the request's state ('W', see {@see LotClient}), through the master, which starts one per
 * slot and file as it is first needed (see {@see Supervisor}). It loads the file once, in the
 * global scope, as it starts, with swerve's services (pub/sub, the container's limits) running.
 *
 * The master tells one to stop over its own socket (descriptor 4): 'S' (shutdown) or 'L'
 * (reload). It takes no more WebSockets, and those it serves get a WorkerStoppingException
 * (closing with 1001, or 1012 on a reload); then it exits.
 *
 * The master's first packet over descriptor 3 is 'B' + the bootstrap file's path; then come the
 * WebSockets, 'W' + what moves: [handler, the request's state ({@see SapiRequest::state()}), the
 * WebSocket's extensions], after a letter: 's', serialized by PHP (the handler names a function
 * or a static method), or 'c', by phasync/serializor (the handler is a closure). Unsigned: the
 * socket it comes over is open only to swerve's own processes.
 *
 * @internal
 */
final class WebSocketWorker
{
    /** The socket the master hands this process the WebSockets over. */
    private const FD = 3;

    /** The master's socket to this process alone. */
    private const CONTROL_FD = 4;

    /** Seconds the WebSockets have to close once told to stop. */
    private const STOP_WINDOW = 1.0;

    /** The socket the master hands this process the WebSockets over. */
    private static \Socket $socket;

    /** The exit status when the bootstrap file failed (EX_CONFIG): the master waits a second before starting another. */
    public const BOOTSTRAP_FAILED = 78;

    /** The largest packet: more than a socket's send buffer can hold. */
    private const MAX_PACKET = 1 << 20;

    /**
     * Load the bootstrap file (the master's first packet: 'B' + its path), then serve the
     * WebSockets moved here, until the master says stop, or is gone.
     */
    public static function serve(): void
    {
        $channel   = \fopen('php://fd/' . self::FD, 'r+');
        $socket    = self::$socket = \socket_import_stream($channel);
        $bootstrap = \substr(self::next($channel, $socket)[0] ?? 'B', 1);
        if ('' === $bootstrap) {
            return; // the master is gone
        }
        try {
            ext\include_global($bootstrap);
        } catch (\Throwable $e) {
            self::broken("The WebSocket worker's bootstrap file $bootstrap failed: {exception}", $e, self::BOOTSTRAP_FAILED);
        }
        $taking = phasync::go(static fn () => self::take($channel, $socket));
        $control = \fopen('php://fd/' . self::CONTROL_FD, 'r+');
        $letter  = LotClient::receive($control, \socket_import_stream($control))[0] ?? 'S';
        phasync::cancel($taking);
        $reason = 'L' === $letter ? StopReason::Reload : StopReason::Shutdown;
        Topics::drain($reason);
        phasync::shutdown(self::STOP_WINDOW, new WorkerStoppingException($reason));
    }

    /**
     * Take the WebSockets moved here, and serve each.
     *
     * @param resource $channel
     */
    private static function take($channel, \Socket $socket): void
    {
        // A class the autoload can't give fails the move at once, by its name, instead of
        // arriving as a __PHP_Incomplete_Class that fails later, somewhere in the closure
        \ini_set('unserialize_callback_func', self::class . '::missing');
        while (null !== $packet = self::next($channel, $socket)) {
            [$bytes, $conn] = $packet;
            if ('W' !== $bytes[0] || null === $conn) {
                throw new \UnexpectedValueException("A worker sent '$bytes[0]' to the WebSocket worker");
            }
            try {
                [$handler, $state, $extensions] = 'c' === $bytes[1] ? (new Codec())->unserialize(\substr($bytes, 2)) : \unserialize(\substr($bytes, 2));
            } catch (\Throwable $e) {
                self::broken('A WebSocket could not move to the WebSocket worker: {exception}', $e);
            }
            $stream = \socket_export_stream($conn);
            if (!\is_callable($handler)) {
                // Its route is broken, not the process: the WebSockets of others go on
                Swerve::log()->critical('A WebSocket moved to the WebSocket worker for {handler}, which neither the bootstrap file nor the Composer autoload defines', ['handler' => \is_array($handler) ? \implode('::', $handler) : $handler]);
                \fclose($stream);

                continue;
            }
            $closure = \Closure::fromCallable($handler);
            \stream_set_blocking($stream, false);
            // In a Scope of its own, seeded with the request's state, and a frame of its own:
            // onScopeChange() hooks see it enter
            phasync::go(static fn () => (new \Scope())->run(static fn () => phasync::run(static function () use ($stream, $closure, $state, $extensions): void {
                SapiRequest::seed($state);
                $connection = new StreamDuplex($stream);
                try {
                    WebSocket::run($connection, $closure, extensions: $extensions);
                } catch (ShutdownException) {
                    // the worker stops: the WebSocket said goodbye
                } catch (\Throwable $e) {
                    if ('' !== self::hint($e)) {
                        self::broken('A WebSocket failed: {exception}', $e);
                    }
                    Swerve::log()->error('A WebSocket failed: {exception}', ['exception' => $e]);
                } finally {
                    $connection->close();
                }
            })));
        }
    }

    /**
     * The bootstrap file failed, or what moved can't run here: the application is deployed broken
     * (code it needs is neither in the bootstrap file nor in its Composer autoload). Fatal: logged as critical, and this worker exits with the sockets it
     * serves; the master starts another for the next WebSocket that moves.
     */
    private static function broken(string $message, \Throwable $e, int $status = 70 /* EX_SOFTWARE */): never
    {
        // First of all: the master's next WebSocket for this process fails to send, and goes to the next one
        @\socket_shutdown(self::$socket);
        Swerve::log()->critical($message . self::hint($e), ['exception' => $e]);
        exit($status);
    }

    /** @internal unserialize_callback_func: a class nothing could load */
    public static function missing(string $class): never
    {
        throw new \Error("Class \"$class\" not found");
    }

    /**
     * What to do about a class or function this process does not have: it has what the bootstrap
     * file and the application's Composer autoload give it, and nothing else of the application.
     */
    private static function hint(\Throwable $e): string
    {
        for ($cause = $e; null !== $cause; $cause = $cause->getPrevious()) {
            if (\preg_match('/^(Class|Interface|Trait|Enum) "[^"]+" not found|^Call to undefined function /', $cause->getMessage())) {
                return '. A WebSocket moved to the WebSocket worker has what its bootstrap file loads, the application\'s'
                    . ' Composer autoload (vendor/autoload.php: its classes, and the files of composer.json\'s autoload.files)'
                    . ' and what its closure captured, nothing else: load what it needs in the bootstrap file';
            }
        }

        return '';
    }

    /**
     * The next packet; null once the workers are gone, or this one stops.
     *
     * @param resource $channel
     *
     * @return array{0: string, 1: ?\Socket}|null
     */
    private static function next($channel, \Socket $socket): ?array
    {
        try {
            return LotClient::receive($channel, $socket, self::MAX_PACKET);
        } catch (CancelledException) {
            return null;
        }
    }
}
