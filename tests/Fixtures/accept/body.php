<?php
while (Swerve\Swerve::accept('public/', function () {
    echo strlen(file_get_contents('php://input')), ' ', json_encode($_POST), "\n";
})) {
}
