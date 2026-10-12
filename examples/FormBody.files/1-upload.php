<?php

use phasync\Psr\StringStream;
use Swerve\Psr\FormBody;

$body = "--b\r\n"
    . "Content-Disposition: form-data; name=\"title\"\r\n\r\n"
    . "Holiday\r\n"
    . "--b\r\n"
    . "Content-Disposition: form-data; name=\"photos[]\"; filename=\"beach.txt\"\r\n"
    . "Content-Type: text/plain\r\n\r\n"
    . "sand and sea\r\n"
    . "--b--\r\n";
$form = FormBody::for('POST', 'multipart/form-data; boundary=b', new StringStream($body));

var_export($form->fields());
echo "\n";

$photo = $form->files()['photos'][0];         // a PSR-7 UploadedFileInterface
echo $photo->getClientFilename(), ', ', $photo->getClientMediaType(), ', ', $photo->getSize(), " bytes\n";

$target = sys_get_temp_dir() . '/beach-' . getmypid() . '.txt';
$photo->moveTo($target);                      // kept; files not moved are deleted with the form
echo file_get_contents($target), "\n";
unlink($target);
