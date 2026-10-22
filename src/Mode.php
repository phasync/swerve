<?php

namespace Swerve;

/**
 * How an application runs: what its `swerve.php` returns. One of {@see ClassicMode},
 * {@see PersistentMode} and {@see AsyncMode}.
 *
 * Every mode routes requests the same way, as nginx's `try_files $uri $uri/ /index.php`: an
 * async route first (see {@see withAsync()}), then a file under the public root, then the
 * application.
 *
 * Paths are relative to the working directory, which is `swerve.php`'s own directory while it
 * runs; absolute paths are accepted. They are resolved, and must exist, when the mode is made.
 *
 * @topic modes
 */
abstract class Mode
{
    /** The public root's real path; null: no static files. */
    public readonly ?string $publicRoot;

    /** @var list<array{string, string, object}> pattern, compiled regex, handler: in declaration order */
    private array $async = [];

    /**
     * @throws \InvalidArgumentException when the public root is not a directory
     */
    protected function __construct(?string $publicRoot)
    {
        $this->publicRoot = null === $publicRoot ? null : self::directory($publicRoot);
    }

    /**
     * An async route: requests whose path matches `$pattern` run `$handler` in Swerve's workers,
     * many at a time, whatever the application's own mode. Returns a new instance.
     *
     * The pattern is the body of a regular expression, matched against the request's decoded,
     * normalised path without the query string. One that starts with `/` is anchored at the start
     * of the path, so `'/ws/'` is a prefix; any other matches anywhere, as nginx's `location ~`:
     * `'\.scss$'`. Options go inline: `'^(?i)/admin/'`. `$` is the end of the path, and patterns
     * and paths are UTF-8. The first matching route wins, in declaration order. Async routes come
     * before static files.
     *
     * ```php
     * return ClassicMode::serve('public')
     *     ->withAsync('/ws/', function (ClientRequest $request): void {
     *         // ...
     *     });
     * ```
     *
     * @param object $handler what an {@see Adapter} runs: see {@see Handlers}
     *
     * @throws \InvalidArgumentException for an empty or invalid pattern, or one already added
     */
    public function withAsync(string $pattern, object $handler): static
    {
        if ('' === $pattern) {
            throw new \InvalidArgumentException('withAsync(): the pattern is empty');
        }
        if (\str_contains($pattern, "\x01")) {
            throw new \InvalidArgumentException('withAsync(): the pattern contains the byte \x01, which Swerve uses as its delimiter');
        }
        foreach ($this->async as [$existing]) {
            if ($existing === $pattern) {
                throw new \InvalidArgumentException("withAsync(): the pattern '$pattern' was added already");
            }
        }
        $regex = "\x01" . ('/' === $pattern[0] ? '^' : '') . $pattern . "\x01Du";
        $error = null;
        \set_error_handler(static function (int $no, string $message) use (&$error): bool {
            $error = \preg_replace('/^preg_match\(\): /', '', $message);

            return true;
        });
        try {
            $valid = false !== \preg_match($regex, '');
        } finally {
            \restore_error_handler();
        }
        if (!$valid) {
            throw new \InvalidArgumentException("withAsync(): '$pattern' is not a valid regular expression: " . ($error ?? \preg_last_error_msg()));
        }
        $clone          = clone $this;
        $clone->async[] = [$pattern, $regex, $handler];

        return $clone;
    }

    /**
     * The async routes, in declaration order.
     *
     * @internal
     *
     * @return list<array{string, string, object}> pattern, compiled regex, handler
     */
    public function asyncRoutes(): array
    {
        return $this->async;
    }

    /**
     * The real path of a directory.
     *
     * @internal
     *
     * @throws \InvalidArgumentException when it is not one
     */
    protected static function directory(string $path): string
    {
        $real = \realpath('' === $path ? '.' : $path);
        if (false === $real || !\is_dir($real)) {
            throw new \InvalidArgumentException("The public root $path is not a directory" . (\str_starts_with($path, '/') ? '' : ' (relative to ' . \getcwd() . ')'));
        }

        return $real;
    }
}
