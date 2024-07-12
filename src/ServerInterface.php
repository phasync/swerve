<?php

namespace swerve;

use Closure;
use Fiber;
use Swerve\ConnectionInterface;
use Swerve\Swerve;
use Swerve\ModuleInterface;

/**
 * The interface between the client and the application, for example implementing
 * HTTP, FastCGI, CGI or other protocols.
 * 
 * @package swerve
 */
interface ServerInterface extends ModuleInterface {

    /**
     * Open the socket and start serving requests. The provided closure
     * must be used to register new connections with Swerve.
     */
    public function open(Closure $addConnectionFunction): void;

    /** 
     * Stop serving
     */
    public function close(): void;
}
