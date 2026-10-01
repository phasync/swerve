<?php
namespace Swerve\CLI;

/**
 * A command line option, flag or argument of the `swerve` command.
 *
 * @internal
 */
interface ArgInterface {

    public function getShort(): string;
    public function getLong(): string;
    public function isInvalid(array $options): ?string;
    /**
     * 
     * @return int|string[]|string|null 
     */
    public function getValue(array $options): int|string|array|null;
}