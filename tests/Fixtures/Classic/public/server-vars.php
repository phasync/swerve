<?php

echo json_encode([
    'sapi'             => PHP_SAPI,
    'script_name'      => $_SERVER['SCRIPT_NAME'],
    'script_filename'  => $_SERVER['SCRIPT_FILENAME'],
    'php_self'         => $_SERVER['PHP_SELF'],
    'path_info'        => $_SERVER['PATH_INFO'] ?? null,
    'document_root'    => $_SERVER['DOCUMENT_ROOT'],
    'request_uri'      => $_SERVER['REQUEST_URI'],
    'query_string'     => $_SERVER['QUERY_STRING'],
    'remote_addr'      => $_SERVER['REMOTE_ADDR'],
    'http_host'        => $_SERVER['HTTP_HOST'] ?? null,
]);
