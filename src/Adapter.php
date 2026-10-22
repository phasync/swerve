<?php

namespace Swerve;

/**
 * Runs one kind of handler: what {@see PersistentMode}, {@see AsyncMode} and
 * {@see Mode::withAsync()} are given. Register one with {@see Handlers::register()}, from the
 * package's Composer autoload "files", so that it is there before `swerve.php` runs.
 *
 * ```php
 * final class SymfonyAdapter implements Swerve\Adapter
 * {
 *     public function canHandle(object $handler, Swerve\Mode $mode): bool
 *     {
 *         if (!$handler instanceof HttpKernelInterface) {
 *             return false;
 *         }
 *         if ($mode instanceof Swerve\AsyncMode) {
 *             throw new LogicException('A Symfony kernel keeps request state in services: use PersistentMode');
 *         }
 *
 *         return true;
 *     }
 *
 *     public function handle(Swerve\ClientRequest $request, object $handler): void
 *     {
 *         // build the framework's request from $request, run $handler, write its response
 *     }
 * }
 * ```
 *
 * @topic adapters
 */
interface Adapter
{
    /**
     * Whether this adapter runs `$handler` in `$mode` (an async route's mode is an {@see AsyncMode}).
     * Asked once, at startup, of every registered adapter: exactly one must answer yes.
     *
     * @throws \LogicException to refuse a mode it can't run the handler in safely, with the reason
     */
    public function canHandle(object $handler, Mode $mode): bool;

    /**
     * Serve one request with `$handler`: read the request and write the response on `$request`.
     * Called for every request; one-time work belongs in the constructor or the first call.
     */
    public function handle(ClientRequest $request, object $handler): void;
}
