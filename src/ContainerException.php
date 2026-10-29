<?php

namespace Swerve;

/**
 * A service's factory failed, or returned no object: the factory's exception is the previous one.
 *
 * @topic resource-management
 */
final class ContainerException extends \RuntimeException implements \Psr\Container\ContainerExceptionInterface
{
}
