<?php

namespace Swerve;

use Swerve\Adapters\ClientRequestAdapter;
use Swerve\Adapters\Psr15Adapter;
use Swerve\Adapters\Psr7Adapter;
use Swerve\Adapters\ScriptAdapter;

/**
 * The adapters that run handlers. Swerve ships four:
 *
 * | Handler | Runs as |
 * |---|---|
 * | `function (ClientRequest $request): void` | Swerve's native handler: reads the body and writes the response on the request |
 * | `function (ServerRequestInterface $request): ResponseInterface` | PSR-7; also `$psr15->handle(...)` |
 * | a PSR-15 `RequestHandlerInterface` | PSR-15 |
 * | `function (): void` | a classic script: superglobals, `php://input`, `echo`, `header()`; the output and headers become the response |
 *
 * Framework packages register more, from their Composer autoload "files":
 *
 * ```php
 * Swerve\Handlers::register(new SymfonyAdapter());
 * ```
 *
 * At startup every handler is offered to every adapter, and exactly one must take it.
 *
 * @topic adapters
 */
final class Handlers
{
    /** @var list<Adapter> registered by packages */
    private static array $adapters = [];

    /** @var list<Adapter>|null Swerve's own */
    private static ?array $core = null;

    /** Handler namespaces, and the package with their adapter. */
    private const PACKAGES = [
        'Symfony\\'     => 'phasync/swerve-symfony',
        'Illuminate\\'  => 'phasync/swerve-laravel',
        'yii\\'         => 'phasync/swerve-yii',
        'Cake\\'        => 'phasync/swerve-cakephp',
        'CodeIgniter\\' => 'phasync/swerve-codeigniter',
        'Laminas\\'     => 'phasync/swerve-laminas',
        'Spiral\\'      => 'phasync/swerve-spiral',
    ];

    private function __construct()
    {
    }

    /**
     * Add an adapter, for the handlers it takes.
     */
    public static function register(Adapter $adapter): void
    {
        self::$adapters[] = $adapter;
    }

    /**
     * The one adapter that runs `$handler` in `$mode`.
     *
     * @internal
     *
     * @throws \LogicException when no adapter, or more than one, takes it; or one refuses the mode
     */
    public static function resolve(object $handler, Mode $mode): Adapter
    {
        self::$core ??= [new ClientRequestAdapter(), new Psr7Adapter(), new Psr15Adapter(), new ScriptAdapter()];
        $taken = [];
        foreach ([...self::$adapters, ...self::$core] as $adapter) {
            if ($adapter->canHandle($handler, $mode)) {
                $taken[] = $adapter;
            }
        }
        if (1 === \count($taken)) {
            return $taken[0];
        }
        $what = self::describe($handler);
        if ([] === $taken) {
            foreach ([$handler::class, ...\class_parents($handler), ...\class_implements($handler)] as $class) {
                foreach (self::PACKAGES as $prefix => $package) {
                    if (\str_starts_with($class, $prefix)) {
                        throw new \LogicException("No adapter runs $what: install $package");
                    }
                }
            }
            throw new \LogicException("No adapter runs $what. Swerve runs function (ClientRequest \$request): void, "
                . 'function (ServerRequestInterface $request): ResponseInterface, a PSR-15 RequestHandlerInterface and function (): void; '
                . 'framework packages add more');
        }

        throw new \LogicException("Several adapters run $what (" . \implode(', ', \array_map(static fn (Adapter $a) => $a::class, $taken)) . '): '
            . 'pass the one method to run as a first-class callable, such as $kernel->handle(...)');
    }

    /** A handler, for a message: its class, or a closure's signature. */
    private static function describe(object $handler): string
    {
        if (!$handler instanceof \Closure) {
            return $handler::class;
        }
        $f = new \ReflectionFunction($handler);

        return 'the closure function (' . \implode(', ', \array_map(static fn (\ReflectionParameter $p) => \trim(($p->getType() ?? '') . ' $' . $p->getName()), $f->getParameters())) . ')'
            . ($f->hasReturnType() ? ': ' . $f->getReturnType() : '')
            . ' (' . $f->getFileName() . ':' . $f->getStartLine() . ')';
    }
}
