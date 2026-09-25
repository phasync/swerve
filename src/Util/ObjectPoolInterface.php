<?php

namespace Swerve\Util;

interface ObjectPoolInterface
{
    public function returnToPool(): void;
}
