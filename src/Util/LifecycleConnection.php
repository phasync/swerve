<?php

namespace Swerve\Util;

/**
 * The master's record of one lifecycle's connection, see Cluster.
 *
 * @internal
 */
final class LifecycleConnection
{
    /** Said hello; loading the application. */
    public const STARTING = 0;
    /** Sent 'R': accept()ing. */
    public const SERVING = 1;
    /** Asked to drain ('T', or SIGTERM from outside): finishing its requests, then ending. */
    public const DRAINING = 2;

    public int $state = self::STARTING;
    public ?float $readyAt = null;
    public ?float $drainingSince = null;

    /** Sent 'Q': it served, so it started fine. */
    public bool $served = false;
    /** Sent 'C': recycling (logged only; the ext and the lifecycle's own linger timer do the rest). */
    public bool $recycle = false;
    /** Draining, but keeping its upgraded connections until they close or the linger time is over. */
    public bool $lingering = false;
    /** The takeover of its slot by a later lifecycle was logged, see Cluster. */
    public bool $takeoverLogged = false;
    /** Sent 'F': a PHP fatal error ends it. */
    public bool $fatal = false;

    /** Bytes read from it that don't make a whole frame yet, see Topics::parse(). */
    public string $in = '';
    /** Bytes for it that its connection did not take yet. */
    public string $out = '';
    /** Why the master SIGKILLed it, for the exit log; set once, so it is killed once. */
    public ?string $killReason = null;

    /**
     * @param resource|null $pipe  the master's end of its connection; null once it reached EOF
     * @param int           $inbox its inbox, see Inboxes
     */
    public function __construct(
        public readonly int $pid,
        public readonly int $slot,
        public readonly int $generation,
        public $pipe,
        public readonly float $started,
        public float $lastSeen,
        public readonly int $inbox,
    ) {
    }
}
