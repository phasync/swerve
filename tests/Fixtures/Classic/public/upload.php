<?php

$out = [];
foreach ($_FILES as $field => $file) {
    $out[$field] = ['name' => $file['name'], 'size' => $file['size'], 'content_b64' => \base64_encode((string) \file_get_contents($file['tmp_name']))];
}
echo json_encode($out);
