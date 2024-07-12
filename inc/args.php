<?php

use Swerve\CLI\Args;
use Swerve\CLI\Argument;
use Swerve\CLI\Flag;
use Swerve\CLI\Option;

return (function () {
    $addr_validator = function ($value) {
        $parts = \explode(':', $value);
        if (count($parts) !== 2) {
            return 'Invalid format (<ip-address>:<port> required)';
        }
        if (false === \filter_var($parts[0], \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4 | \FILTER_FLAG_IPV6)) {
            return 'Invalid ip address';
        }
        if (false === \filter_var($parts[1], \FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => 65535,
            ],
        ])) {
            return 'Invalid port number. Must be between 1 and 65535.';
        }

        return null;
    };

    $args = new Args();
    $args->add('monitor', new Flag(
        'm', 'monitor', 'Monitor source code and reload automatically'
    ));
    $args->add('daemon', new Flag(
        'd', '', 'Run as daemon',
    ));
    $args->add('workers', new Option(
        'w', 'workers', 'Number of worker processes',
        default: 'auto',
        validator: function ($value) {
            if ($value === 'auto') {
                return null;
            }
            if (false === \filter_var($value, \FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 256,
                ],
            ])) {
                return 'Integer between 1 and 256 required';
            }

            return null;
        },
        placeholder: 'processes'
    ));
    $args->add('fastcgi', new Option(
        '', 'fastcgi', 'IP and port for FastCGI server',
        placeholder: 'ip:port',
        validator: $addr_validator,
        multiple: true
    ));
    $args->add('http', new Option(
        '', 'http', 'IP and port for HTTP server',
        default: '127.0.0.1:8080',
        placeholder: 'ip:port',
        validator: $addr_validator,
        multiple: true
    ));
    $args->add('https', new Option(
        '', 'https', 'IP and port for HTTPS server',
        placeholder: 'ip:port',
        validator: $addr_validator,
        multiple: true,
    ));
    $args->add('log', new Option(
        '', 'log', 'Log errors to file',
        placeholder: 'path',
        validator: function ($value) {
            $dir = \dirname($value);
            if (!\is_dir($dir)) {
                return "Directory $dir not found";
            }

            return null;
        },
    ));
    $args->add('help', new Flag(
        short: 'h',
        long: 'help',
        description: 'Display this help message'
    ));
    $args->add('verbosity', new Flag(
        'v', 'verbose', 'Increase logging verbosity, repeat for higher verbosity',
        multiple: true
    ));
    $args->add('quiet', new Flag(
        'q', 'quiet', 'Suppress all output',
    ));
    $args->add('swervefile', new Argument('swerve.php', 'Full path to application php file', './swerve.php'));

    return $args;
})();
