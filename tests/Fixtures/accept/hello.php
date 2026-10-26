<?php
while (Swerve\Swerve::accept('public/', function () {
    header('Content-Type: text/plain');
    echo 'Hello from ', getmypid(), "\n";
})) {
}
