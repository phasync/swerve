<?php

namespace Swerve;

/**
 * {@see Container::get()} of an id nobody added.
 *
 * @topic resource-management
 */
final class ServiceNotFoundException extends \LogicException implements \Psr\Container\NotFoundExceptionInterface
{
}
