<?php
while (Swerve\Swerve::accept(null, function () {
    header('Content-Type: text/plain');
    echo 'Hello from ', getmypid(), "\n";
})) {
}
