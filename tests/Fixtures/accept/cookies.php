<?php
while (Swerve\Swerve::accept(null, function () {
    echo json_encode($_COOKIE);
})) {
}
