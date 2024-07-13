<?php

function buildCaddyConfig(array $unixSockets, array $httpPorts, array $httpsPorts)
{
    // Add Unix socket paths to the listen array and define reverse proxy routes using FastCGI
    $upstreams = [];
    foreach ($unixSockets as $socketPath) {
        $socketListenPath = 'unix/'.$socketPath;
        $upstreams[] = [
            'dial' => $socketListenPath,
        ];
    }

    $listens = [];
    // Add HTTP ports to the listen array (each port should include the IP and port, e.g., "192.168.1.1:80")
    foreach ($httpPorts as $port) {
        $listens[] = $port;
    }

    // Initialize the JSON configuration for the Caddy server
    $jsonConfig = [
        /*
        'logging' => [
            'logs' => [
                'default' => [
                    'level' => 'DEBUG',
                ],
            ],
        ],
        */
        'apps' => [
            'http' => [
                'servers' => [
                    'srv0' => [
                        'listen' => $listens,
                        'routes' => [
                            [
                                'handle' => [
                                    [
                                        'handler' => 'reverse_proxy',
                                        'upstreams' => $upstreams,
                                        'transport' => [
                                            'protocol' => 'fastcgi',
                                        ],
                                    ],
                                ],
                                'terminal' => true,
                            ],
                        ],
                        'automatic_https' => [
                            'disable' => true,
                        ],
                    ],
                ],
            ],
        ],
    ];

    // echo json_encode($jsonConfig, JSON_PRETTY_PRINT)."\n";

    return $jsonConfig;
}
