<?php

// A bare closure, not the mode swerve.php must return
return static function (Swerve\ClientRequest $request): void {
    $request->end();
};
