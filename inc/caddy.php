<?php
function buildCaddyConfig(array $unixSockets, array $httpPorts, array $httpsPorts) {
    // Initialize the JSON configuration for the Caddy server
    $jsonConfig = [
        'apps' => [
            'http' => [
                'servers' => [
                    'srv0' => [
                        'listen' => [],
                        'routes' => []
                    ]
                ]
            ]
        ]
    ];

    // Add HTTP ports to the listen array (each port should include the IP and port, e.g., "192.168.1.1:80")
    foreach ($httpPorts as $port) {
        $jsonConfig['apps']['http']['servers']['srv0']['listen'][] = $port;
    }

    // Add HTTPS ports to the listen array (each port should include the IP and port, e.g., "192.168.1.1:443")
    foreach ($httpsPorts as $port) {
        $jsonConfig['apps']['http']['servers']['srv0']['listen'][] = $port;
    }

    // Add Unix socket paths to the listen array and define reverse proxy routes using FastCGI
    foreach ($unixSockets as $socketPath) {
        $socketListenPath = "unix/" . $socketPath;
        $jsonConfig['apps']['http']['servers']['srv0']['listen'][] = $socketListenPath;
        $route = [
            'handle' => [
                [
                    'handler' => 'reverse_proxy',
                    'upstreams' => [
                        ['dial' => $socketListenPath]
                    ],
                    'transport' => [
                        'protocol' => 'fastcgi'
                    ]
                ]
            ]
        ];
        $jsonConfig['apps']['http']['servers']['srv0']['routes'][] = $route;
    }

    return $jsonConfig;
}