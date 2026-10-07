<?php

/*
 * Workers don't stat-check source files: opcache.validate_timestamps is off, so changed files
 * reach the workers through a reload (--watch, SIGHUP), not through each worker's own checks.
 */

test('workers run with opcache timestamp checks off', function () {
    [$process, $addr] = native_start('app.php', [], 1, ['-d', 'opcache.enable_cli=1']);
    try {
        expect(http_get($addr, '/ini?k=opcache.validate_timestamps'))->toBe('0');
    } finally {
        native_stop($process);
    }
})->skip(!extension_loaded('Zend OPcache'), 'needs opcache');
