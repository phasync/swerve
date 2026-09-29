<?php

namespace Swerve\Http;

use phasync\Context\SwitchAwareInterface;

/**
 * The phasync context of a request run by Virtual::run(): its $_GET, $_POST, $_COOKIE, $_SERVER,
 * $_FILES, $_REQUEST and $_SESSION are swapped in while its coroutines run. $_SESSION is rebound, not copied: it must stay the
 * reference ext/session holds, which is what gets saved. The others are copied: some of PHP's own
 * code reads $_SERVER from the symbol table without following a reference.
 *
 * @internal Virtual::run()
 */
final class Superglobals implements SwitchAwareInterface
{
    /** The request's, while another request runs; null until its first suspend(). */
    private ?array $own = null;

    /** @param array $outer what the worker had, restored while other requests run */
    public function __construct(private readonly array $outer)
    {
    }

    public function resume(): void
    {
        if (null === $this->own) {
            return; // entering: PHP builds the request's superglobals as it starts
        }
        unset($_SESSION); // undefined, not null, without a session
        [$_GET, $_POST, $_COOKIE, $_SERVER, $_FILES, $_REQUEST] = $this->own;
        if (isset($this->own[6])) {
            $_SESSION = &$this->own[6];
        }
    }

    public function suspend(): void
    {
        $this->own = [$_GET, $_POST, $_COOKIE, $_SERVER, $_FILES, $_REQUEST, &$_SESSION];
        unset($_SESSION);
        [$_GET, $_POST, $_COOKIE, $_SERVER, $_FILES, $_REQUEST] = $this->outer;
    }

    /** The worker's superglobals now, as the $outer of a request's. */
    public static function current(): array
    {
        return [$_GET ?? [], $_POST ?? [], $_COOKIE ?? [], $_SERVER, $_FILES ?? [], $_REQUEST ?? []];
    }
}
