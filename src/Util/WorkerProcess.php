<?php

namespace Swerve\Util;

/**
 * The master's record of one worker process, see Cluster.
 */
final class WorkerProcess
{
    /** Forked; loading the application and opening its listeners. */
    public const STARTING = 0;
    /** Sent 'R': listening and serving. */
    public const SERVING = 1;
    /** Asked to drain ('T', or SIGTERM from outside): finishing its requests, then exiting. */
    public const DRAINING = 2;

    public int $state = self::STARTING;
    public ?float $readyAt = null;
    public ?float $drainingSince = null;

    /** Sent 'Q': it serves requests, so it started fine, see Cluster::exited(). */
    public bool $served = false;
    /** Sent 'C': asks to be replaced, see Cluster::handover(). */
    public bool $recycle = false;
    /** Sent 'F': a PHP fatal error ends it. */
    public bool $fatal = false;

    /** Bytes read from it that don't make a whole frame yet, see Topics::parse(). */
    public string $in = '';
    /** Bytes for it that its pipe did not take yet, see Cluster::send(). */
    public string $out = '';
    /** Why the master SIGKILLed it, for the exit log; set once, so it is killed once. */
    public ?string $killReason = null;

    /**
     * @param resource|null $pipe     the master's end of the socket pair; null once it reached EOF
     * @param int           $inbox    its inbox, see Inboxes
     * @param int|null      $replaces the SERVING worker this one takes over from, in a reload or recycle
     */
    public function __construct(
        public readonly int $pid,
        public readonly int $slot,
        public readonly int $generation,
        public $pipe,
        public readonly float $started,
        public float $lastSeen,
        public readonly int $inbox,
        public readonly ?int $replaces,
    ) {
    }
}
