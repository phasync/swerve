<?php
namespace Swerve\CLI;

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