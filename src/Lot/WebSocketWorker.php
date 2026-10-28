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
        while (null !== $packet = self::next($channel, $socket)) {
            [$bytes, $conn] = $packet;
            if ('W' !== $bytes[0] || null === $conn) {
                throw new \UnexpectedValueException("A worker sent '$bytes[0]' to the WebSocket worker");
            }
            [$closure, $state] = $codec->unserialize(\substr($bytes, 1));
            $stream  = \socket_export_stream($conn);
            \stream_set_blocking($stream, false);
            // In a Scope of its own, seeded with the request's state, and a frame of its own:
            // onScopeChange() hooks see it enter
            phasync::go(static fn () => (new \Scope())->run(static fn () => phasync::run(static function () use ($stream, $closure, $state): void {
                SapiRequest::seed($state);
                $connection = new StreamDuplex($stream);
                try {
                    WebSocket::run($connection, $closure);
                } catch (ShutdownException) {
                    // the worker stops: the WebSocket said goodbye
                } catch (\Throwable $e) {
                    Swerve::log()->error('A WebSocket failed: {exception}', ['exception' => $e]);
                } finally {
                    $connection->close();
                }
            })));
        }
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
