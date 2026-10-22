<?php

echo json_encode(['script_name' => $_SERVER['SCRIPT_NAME'], 'request_uri' => $_SERVER['REQUEST_URI']]);
