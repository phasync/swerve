<?php

namespace Swerve\Lot;

use phasync;
use phasync\CancelledException;
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
 * The process that serves the WebSockets of short-lived lifecycles: an application whose
 * swerve.php ends after each request (WordPress's) can't keep a connection, so its worker
 * answers the handshake and moves the connection, with the Swerve::upgrade() closure, here
 * ('W', see {@see LotClient}). The master starts them as they are needed, up to one per worker,
 * and they take the WebSockets that wait in turn; one that exits is replaced.
 *
 * The master tells one to stop over its own socket (descriptor 4): 'S' (shutdown) or 'L'
 * (reload). It takes no more WebSockets, and those it serves get a WorkerStoppingException
 * (closing with 1001, or 1012 on a reload); then it exits.
 *
 * The closure arrives with the request's state ({@see SapiRequest::state()}), serialized with phasync/serializor and unsigned: the socket it comes
 * over is open only to swerve's own processes.
 *
 * @internal
 */
final class WebSocketWorker
{
    /** The socket the workers move WebSockets over. */
    private const FD = 3;

    /** The master's socket to this process alone. */
    private const CONTROL_FD = 4;

    /** Seconds the WebSockets have to close once told to stop. */
    private const STOP_WINDOW = 1.0;

    /** The largest packet: more than a socket's send buffer can hold. */
    private const MAX_PACKET = 1 << 20;

    /** Serve the WebSockets moved here, until the master says stop, or is gone. */
    public static function serve(): void
    {
        $taking = phasync::go(self::take(...));
        $control = \fopen('php://fd/' . self::CONTROL_FD, 'r+');
        $letter  = LotClient::receive($control, \socket_import_stream($control))[0] ?? 'S';
        phasync::cancel($taking);
        Topics::drain();
        phasync::shutdown(self::STOP_WINDOW, new WorkerStoppingException('L' === $letter ? StopReason::Reload : StopReason::Shutdown));
    }

    /** Take the WebSockets moved here, and serve each. */
    private static function take(): void
    {
        $channel = \fopen('php://fd/' . self::FD, 'r+');
        $socket  = \socket_import_stream($channel);
        $codec   = new Codec();
        // A class the autoload can't give fails the move at once, by its name, instead of
        // arriving as a __PHP_Incomplete_Class that fails later, somewhere in the closure
        \ini_set('unserialize_callback_func', self::class . '::missing');
        while (null !== $packet = self::next($channel, $socket)) {
            [$bytes, $conn] = $packet;
            if ('W' !== $bytes[0] || null === $conn) {
                throw new \UnexpectedValueException("A worker sent '$bytes[0]' to the WebSocket worker");
            }
            try {
                [$closure, $state, $extensions] = $codec->unserialize(\substr($bytes, 1));
            } catch (\Throwable $e) {
                self::broken('A WebSocket could not move to the WebSocket worker: {exception}', $e);
            }
            $stream  = \socket_export_stream($conn);
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
     * A moved closure can't run here: the application is deployed broken (code it needs is not in
     * its Composer autoload). Fatal: logged as critical, and this worker exits with the sockets it
     * serves; the master starts another for the next WebSocket that moves.
     */
    private static function broken(string $message, \Throwable $e): never
    {
        Swerve::log()->critical($message . self::hint($e), ['exception' => $e]);
        exit(70); // EX_SOFTWARE
    }

    /** @internal unserialize_callback_func: a class nothing could load */
    public static function missing(string $class): never
    {
        throw new \Error("Class \"$class\" not found");
    }

    /**
     * What to do about a class or function this process does not have: it has what the
     * application's Composer autoload gives it, and nothing else of the application.
     */
    private static function hint(\Throwable $e): string
    {
        for ($cause = $e; null !== $cause; $cause = $cause->getPrevious()) {
            if (\preg_match('/^(Class|Interface|Trait|Enum) "[^"]+" not found|^Call to undefined function /', $cause->getMessage())) {
                return '. A WebSocket moved to the WebSocket worker has the application\'s Composer autoload'
                    . ' (vendor/autoload.php: its classes, and the files of composer.json\'s autoload.files) and what its'
                    . ' closure captured, nothing else: add what it needs to composer.json\'s autoload';
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
