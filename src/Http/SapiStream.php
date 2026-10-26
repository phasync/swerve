<?php

namespace Swerve\Http;

use phasync\IOException;
use phasync\TimeoutException;
use Swerve\ClientRequest;

/**
 * A request's body or response body as a PHP stream, for {@see SapiRequest}: a userland
 * stream wrapper reading and writing the ClientRequest itself. A write the client is slow to
 * take suspends the calling coroutine, the request's own.
 *
 * @internal
 */
final class SapiStream
{
    public const PROTOCOL = 'swerve-sapi';

    /** @var resource|null set by PHP */
    public $context;

    private ClientRequest $client;

    /** The client went away: later writes are discarded, as a SAPI's are. */
    private bool $gone = false;

    /** @return resource */
    public static function open(string $what, string $mode, $context)
    {
        static $registered = false;
        if (!$registered) {
            \stream_wrapper_register(self::PROTOCOL, self::class);
            $registered = true;
        }

        return \fopen(self::PROTOCOL . "://$what", $mode, false, $context);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $this->client = \stream_context_get_options($this->context)[self::PROTOCOL]['client'];

        return true;
    }

    public function stream_write(string $data): int
    {
        if (!$this->gone) {
            try {
                $this->client->write($data);
            } catch (IOException|TimeoutException) {
                $this->gone = true;

                return 0; // the extension then makes connection_aborted() true
            }
        }

        return \strlen($data);
    }

    public function stream_read(int $count): string|false
    {
        try {
            return $this->client->read($count);
        } catch (IOException|TimeoutException) {
            return false;
        }
    }

    public function stream_eof(): bool
    {
        return $this->client->eof();
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }
}
