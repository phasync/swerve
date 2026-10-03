<?php

namespace Swerve\Util;

use phasync;

/**
 * The output buffer at the bottom of a worker's stack, installed without phasync-ext: anything
 * that reaches it ends the worker.
 *
 * Without the extension, output buffers belong to the process, not the request: one request's
 * echo would be delivered to whichever request flushes next. So an application served by swerve
 * returns its output in the response, and output while a request is being handled that is not in
 * the response is a fatal error with a message on stderr (not through output buffering) that says
 * what was echoed, in which request and where. Output while no request is being handled (the
 * application loading, a banner, debugging) passes through to the worker's standard output.
 * Buffers an application starts on top of the guard keep working: it sees only what falls
 * through them. It can't be removed by ob_end_clean() and the like (see docs/stray-output.md).
 *
 * @internal
 */
final class StrayOutput
{
    /** Requests being handled (see Dispatcher::dispatch()): the guard only acts while there is one. */
    public static int $inFlight = 0;

    /** Bytes of the output the message quotes. */
    private const QUOTE = 200;

    /** Put the guard at the bottom of the output buffers, before the application loads. */
    public static function install(): void
    {
        \ob_start([self::class, 'guard'], 1, \PHP_OUTPUT_HANDLER_STDFLAGS & ~\PHP_OUTPUT_HANDLER_REMOVABLE);
    }

    /**
     * Output handler, called at once with whatever reaches the buffer, in the coroutine that echoed.
     *
     * @internal
     */
    public static function guard(string $buffer): string
    {
        if (0 === self::$inFlight || '' === $buffer) {
            return $buffer; // no request to harm, or a flush or the exit of the process
        }
        $context = \Fiber::getCurrent() ? phasync::getContext() : null;
        if (!$context instanceof LoggingContext) {
            $request = 'unknown (a coroutine with a context of its own)';
        } elseif (null === $context->request) {
            $request = 'none (a background coroutine)';
        } else {
            $request = $context->request->getMethod() . ' ' . $context->request->getRequestTarget();
        }
        $at = 'unknown';
        foreach (\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 2) as $frame) {
            if (isset($frame['file'])) { // the handler's own frame has the echo's file and line, a function such as var_dump() its caller's
                $at = $frame['file'] . ':' . $frame['line'];
                break;
            }
        }
        \fwrite(\STDERR, \sprintf(
            "Stray output is not compatible with swerve.\n  Output:  \"%s\"%s (%d bytes)\n  Request: %s\n  At:      %s\n  Remedy:  serve this application with php-fpm or similar, or install phasync-ext.\n",
            \addcslashes(\substr($buffer, 0, self::QUOTE), "\0..\37\"\\\177..\377"),
            \strlen($buffer) > self::QUOTE ? ' ...' : '',
            \strlen($buffer),
            $request,
            $at,
        ));
        exit(Worker::EXIT_STRAY_OUTPUT);
    }
}
