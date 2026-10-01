<?php

namespace Swerve;

use phasync;
use Swerve\Util\System;

/**
 * A handle on a name that one holder at a time can hold across the whole server, from {@see Swerve::claim()}.
 *
 * The handle claims nothing until `acquire()`.
 *
 * ```php
 * if ($claim = Swerve::claim('nightly-report')->acquire()) {
 *     ...
 * }                                     // released when $claim goes out of scope
 * ```
 *
 * A claim is a file in a temporary directory (below $SWERVE_TMPDIR, by default the system's)
 * that the master creates before it forks the workers: a hard link to a file with the pid of the
 * holder in it. Linking is atomic and fails while the
 * name is held, so the first to try when the name is free wins, there is no queue, and a waiting
 * acquire() tries again every 20 ms. It is held until it is released, its handle is destroyed, or
 * its worker exits or dies (the master clears what a dead worker held). Draining does not release
 * a claim: a long-lived holder should release it itself when Swerve::draining(), so that a reload
 * is not held up. Names are a namespace of their own, apart from the cache's keys. Without a
 * master it works the same, in a directory of the process that dies with it: two handles
 * conflict, also in one process.
 *
 * ```php
 * $claim = Swerve::claim('import');
 * if ($claim->acquire(timeout: 5.0)) {      // tries every 20 ms for up to 5 s
 *     import_feed();
 *     $claim->release();
 * }
 * ```
 *
 * @see Swerve::claim
 * @see Swerve::cache
 * @see Swerve::draining
 */
final class Claim
{
    /** Seconds between a waiting acquire()'s attempts. */
    private const POLL = 0.02;

    private static ?string $directory = null;
    private static bool $removed      = false;
    /** The pid that pidFile() was written for. */
    private static int $written = 0;

    private readonly string $path;
    private bool $held = false;

    /**
     * A handle on `$name`; {@see Swerve::claim()} is how an application gets one.
     *
     * @param string $name any non-empty string
     *
     * @throws \InvalidArgumentException for an empty name
     */
    public function __construct(public readonly string $name)
    {
        if ('' === $name) {
            throw new \InvalidArgumentException('A claim needs a name');
        }
        $this->path = self::directory() . '/' . \hash('sha256', $name);
    }

    /**
     * The directory of the claims, below $SWERVE_TMPDIR or the system's temporary directory, see
     * System::tempDirectory(). The master creates it before it forks, so that the workers inherit
     * it; without a master it is created here, at first use.
     *
     * @internal
     */
    public static function directory(): string
    {
        return self::$directory ??= System::tempDirectory('swerve-claims', static function () { self::$removed = true; });
    }

    /**
     * The master, when worker $pid has exited: what it held is free.
     *
     * @internal
     */
    public static function clear(int $pid): void
    {
        foreach (\glob(self::directory() . '/*') as $file) {
            // A live worker may release its claim between the listing and here
            if ((string) $pid === @\file_get_contents($file)) {
                \unlink($file);
            }
        }
    }

    /**
     * Whether nobody holds the name now; nothing is claimed.
     *
     * Another worker may take the name before the caller acts on the answer: use
     * {@see Claim::acquire()} to take it.
     *
     * @see Claim::held
     */
    public function available(): bool
    {
        \clearstatcache(true, $this->path);

        return !\file_exists($this->path);
    }

    /**
     * Take the name, trying every 20 ms for up to `$timeout` seconds when another holds it.
     *
     * Whoever tries first when the name frees gets it, in no order. Returns this handle when it
     * holds the name, also if it did already; null when it could not.
     *
     * ```php
     * if ($leader = Swerve::claim('leader')->acquire()) {
     *     // this worker holds the name until $leader is released or destroyed
     * }
     * ```
     *
     * @param float $timeout seconds to keep trying while another holds the name; 0 tries once
     *
     * @return static|null this handle, or null when the name is held by another
     *
     * @see Claim::release
     * @see Claim::available
     */
    public function acquire(float $timeout = 0.0): ?static
    {
        $deadline = \hrtime(true) / 1e9 + $timeout;
        // EEXIST is the answer 'held', not an error. (symlink() would be the natural link, but PHP
        // follows an existing link at the destination and creates what it points at: no EEXIST)
        while (!($this->held = $this->held || @\link(self::pidFile(), $this->path))) {
            $left = $deadline - \hrtime(true) / 1e9;
            if ($left <= 0) {
                return null;
            }
            phasync::sleep(\min(self::POLL, $left));
        }

        return $this;
    }

    /** Whether this handle holds the name: it was acquired and not released. */
    public function held(): bool
    {
        return $this->held;
    }

    /**
     * Give the claim up, so that another can take it.
     *
     * Does nothing when this handle holds nothing.
     *
     * @see Claim::acquire
     */
    public function release(): void
    {
        if ($this->held) {
            $this->held = false;
            // Not what another process holds, when this handle was inherited by a fork; nor
            // after the directory was removed at shutdown
            if (!self::$removed && (string) \getmypid() === \file_get_contents($this->path)) {
                \unlink($this->path);
            }
        }
    }

    /** Releases: synchronous, so it cannot suspend. */
    public function __destruct()
    {
        $this->release();
    }

    /** This process's file with its pid in it, which every claim of the process links to. */
    private static function pidFile(): string
    {
        $pid  = \getmypid();
        $file = self::directory() . '/~' . $pid;
        if (self::$written !== $pid) {
            \file_put_contents($file, (string) $pid);
            self::$written = $pid;
        }

        return $file;
    }
}
