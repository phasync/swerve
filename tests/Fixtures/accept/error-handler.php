<?php
// An application's error handler, process-wide as set_error_handler() is: swerve's own work
// between and around requests must not reach it (#112)
final class Caught
{
    /** @var list<string> */
    public static array $errors = [];
}
set_error_handler(static function (int $no, string $message): bool {
    Caught::$errors[] = $message;

    return true;
});
while (Swerve\Swerve::accept(null, static function (): void {
    echo json_encode([Caught::$errors, error_get_last()]);
})) {
}
