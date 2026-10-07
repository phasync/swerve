<?php

/*
 * swerve owns opcache's settings: it restarts itself once with them, so workers run with the
 * opcode cache and the JIT on, and never check source files for changes (a reload does that).
 */

test('workers run with swerve\'s settings: no shared-hosting limits, its opcache configuration', function () {
    [$process, $addr] = native_start('app.php', [], 1);
    try {
        $ini = [];
        foreach (['memory_limit', 'opcache.enable_cli', 'opcache.jit', 'opcache.interned_strings_buffer', 'opcache.validate_timestamps', 'opcache.file_update_protection', 'opcache.enable_file_override', 'opcache.preload'] as $name) {
            $ini[$name] = http_get($addr, "/ini?k=$name");
        }
        expect($ini)->toBe([
            'memory_limit'                    => '-1',
            'opcache.enable_cli'              => '1',
            'opcache.jit'                     => '1054', // tracing without register allocation, see bin/swerve.php
            'opcache.interned_strings_buffer' => '64',
            'opcache.validate_timestamps'     => '0',
            'opcache.file_update_protection'  => '0',
            'opcache.enable_file_override'    => '1',
            'opcache.preload'                 => '',
        ]);
    } finally {
        native_stop($process);
    }
});

test('an explicit -d on the command line wins over swerve\'s opcache settings', function () {
    [$process, $addr] = native_start('app.php', [], 1, ['-d', 'opcache.interned_strings_buffer=16']);
    try {
        expect(http_get($addr, '/ini?k=opcache.interned_strings_buffer'))->toBe('16');
    } finally {
        native_stop($process);
    }
});

