<?php
// Workers are forked from the master: they share its APCu memory, as php-fpm's workers do
while (Swerve\Swerve::accept(null, function () {
    if ('/set' === $_SERVER['REQUEST_URI']) {
        apcu_store('swerve-test', getmypid());
    }
    echo apcu_fetch('swerve-test'), ' ', getmypid();
})) {
}
