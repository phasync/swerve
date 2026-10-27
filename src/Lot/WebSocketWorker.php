<?php

namespace Swerve\Lot;

use phasync;
use phasync\Net\StreamDuplex;
use Serializor\Codec;
use Swerve\Swerve;
use Swerve\WebSocket;

/**
 * The process that serves the WebSockets of short-lived lifecycles: an application whose
 * swerve.php ends after each request (WordPress's) can't keep a connection, so its worker
 * answers the handshake and moves the connection, with the Swerve::upgrade() closure, here
 * ('W', see {@see LotClient}). The master starts them as they are needed, up to one per worker,
 * and they take the WebSockets that wait in turn; one that exits is replaced.
 *
 * The closure arrives serialized with phasync/serializor and unsigned: the socket it comes
 * over is open only to swerve's own processes.
 *
 * @internal
 */
final class WebSocketWorker
{
    /** The socket the workers move WebSockets over. */
    private const FD = 3;

    /** The largest packet: more than a socket's send buffer can hold. */
    private const MAX_PACKET = 1 << 20;

    /** Serve the WebSockets moved here, until the master is gone. */
    public static function serve(): void
    {
        $channel = \fopen('php://fd/' . self::FD, 'r+');
        $socket  = \socket_import_stream($channel);
        $codec   = new Codec();
        while (null !== $packet = LotClient::receive($channel, $socket, self::MAX_PACKET)) {
            [$bytes, $conn] = $packet;
            if ('W' !== $bytes[0] || null === $conn) {
                throw new \UnexpectedValueException("A worker sent '$bytes[0]' to the WebSocket worker");
            }
            $closure = $codec->unserialize(\substr($bytes, 1));
            $stream  = \socket_export_stream($conn);
            \stream_set_blocking($stream, false);
            phasync::go(static function () use ($stream, $closure): void {
                $connection = new StreamDuplex($stream);
                try {
                    WebSocket::run($connection, $closure);
                } catch (\Throwable $e) {
                    Swerve::log()->error('A WebSocket failed: {exception}', ['exception' => $e]);
                } finally {
                    $connection->close();
                }
            });
        }
    }
}
