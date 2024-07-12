<?php

namespace Swerve;

interface ModuleInterface
{
    /**
     * The module name.
     */
    public function getName(): string;

    /**
     * When attached to the Swerve instance.
     */
    public function attach(Swerve $swerve): void;

    /**
     * When detached from the Swerve instance.
     */
    public function detach(): void;
}
