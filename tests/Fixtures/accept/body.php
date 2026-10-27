<?php
while (Swerve\Swerve::accept(null, function () {
    if ([] !== $_GET) {
        echo json_encode($_REQUEST), "\n";

        return;
    }
    echo strlen(file_get_contents('php://input')), ' ', json_encode($_POST), "\n";
})) {
}
