<?php

// PHP's own parsing, the reference: what $_POST and $_FILES hold, as JSON
$describe = static function (array $f) use (&$describe): array {
    // $_FILES keeps name/type/tmp_name/error/size as parallel trees; turn them into one tree
    $out = [];
    foreach ($f as $field => $spec) {
        $out[$field] = (static function ($name, $type, $size, $error, $tmp) use (&$walk) {
            $walk = static function ($name, $type, $size, $error, $tmp) use (&$walk) {
                if (!is_array($name)) {
                    return ['name' => $name, 'type' => $type, 'size' => $size, 'error' => $error, 'md5' => UPLOAD_ERR_OK === $error ? md5_file($tmp) : null];
                }
                $o = [];
                foreach ($name as $k => $_) {
                    $o[$k] = $walk($name[$k], $type[$k], $size[$k], $error[$k], $tmp[$k]);
                }

                return $o;
            };

            return $walk($name, $type, $size, $error, $tmp);
        })($spec['name'], $spec['type'], $spec['size'], $spec['error'], $spec['tmp_name']);
    }

    return $out;
};
header('Content-Type: application/json');
echo json_encode(['post' => $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['CONTENT_TYPE']) && preg_match('#^(application/x-www-form-urlencoded|multipart/form-data)#i', $_SERVER['CONTENT_TYPE']) ? $_POST : null, 'files' => $describe($_FILES), 'input' => file_get_contents('php://input')]);
