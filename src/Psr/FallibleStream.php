<?php

namespace Swerve\Psr;

use phasync\Psr\UnbufferedStream;

/**
 * An {@see UnbufferedStream} whose reader sees the producer's exception, instead of a stream
 * that silently ends wherever the producer happened to fail.
 *
 * A response body produced by a coroutine of its own (the usual way to stream a PSR-7 response
 * progressively) has no other way to tell whoever reads it that it failed: `end()` always means
 * "nothing more, successfully". Calling {@see FallibleStream::fail()} instead ends the stream and
 * makes the next `eof()` (and so the read loop built on it) throw `$e`, which propagates out of
 * the handler's own call stack exactly as it would have, had the handler written to the
 * connection directly: a 500 before the head is sent, the connection aborted after.
 *
 * ```php
 * $stream = new FallibleStream();
 * phasync::go(function () use ($stream, $produce) {
 *     try {
 *         $produce($stream);
 *         $stream->end();
 *     } catch (\Throwable $e) {
 *         $stream->fail($e);
 *     }
 * });
 * ```
 *
 * @internal
 */
final class FallibleStream extends UnbufferedStream
{
    private ?\Throwable $failure = null;

    /** Ends the stream, remembering `$e` for the reader: the next `eof()` throws it. */
    public function fail(\Throwable $e): void
    {
        $this->failure = $e;
        $this->end();
    }

    public function eof(): bool
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return parent::eof();
    }
}
