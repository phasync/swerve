<?php

echo json_encode([
    'path_info'   => $_SERVER['PATH_INFO'] ?? null,
    'script_name' => $_SERVER['SCRIPT_NAME'],
    'php_self'    => $_SERVER['PHP_SELF'],
]);
