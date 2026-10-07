<?php

namespace Swerve;

/** Why a worker stops, see WorkerStoppingException. */
enum StopReason
{
    /** swerve stops: SIGTERM, Ctrl+C, or the master died. Tell clients the server is going away. */
    case Shutdown;
    /** swerve restarts with new code. A WebSocket closes with 1012 (Service Restart): reconnect. */
    case Reload;
    /** Only this worker is replaced (--max-requests, --max-memory); the server goes on. */
    case Recycle;
}
