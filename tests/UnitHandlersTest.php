<?php

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Adapter;
use Swerve\Adapters\ClientRequestAdapter;
use Swerve\Adapters\Psr15Adapter;
use Swerve\Adapters\Psr7Adapter;
use Swerve\Adapters\ScriptAdapter;
use Swerve\AsyncMode;
use Swerve\ClientRequest;
use Swerve\Handlers;
use Swerve\Mode;
use Swerve\PersistentMode;

beforeEach(function () {
    $this->registered = (new ReflectionProperty(Handlers::class, 'adapters'))->getValue();
    $this->mode       = PersistentMode::serve(null, new stdClass());
});
afterEach(fn () => (new ReflectionProperty(Handlers::class, 'adapters'))->setValue(null, $this->registered));

it('picks the core adapter by the handler\'s shape', function () {
    $psr15 = new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            throw new LogicException();
        }
    };
    expect(Handlers::resolve(static function (ClientRequest $r): void {}, $this->mode))->toBeInstanceOf(ClientRequestAdapter::class)
        ->and(Handlers::resolve(static fn (ServerRequestInterface $r) => null, $this->mode))->toBeInstanceOf(Psr7Adapter::class)
        ->and(Handlers::resolve($psr15->handle(...), $this->mode))->toBeInstanceOf(Psr7Adapter::class)
        ->and(Handlers::resolve($psr15, $this->mode))->toBeInstanceOf(Psr15Adapter::class);
    expect(Handlers::resolve(static function (): void {}, $this->mode))->toBeInstanceOf(ScriptAdapter::class);
});

it('names what it can run when nothing takes the handler', function () {
    expect(fn () => Handlers::resolve(static function ($request) {}, $this->mode))
        ->toThrow(LogicException::class, 'No adapter runs the closure function ($request) (' . __FILE__)
        ->and(fn () => Handlers::resolve(new stdClass(), $this->mode))
        ->toThrow(LogicException::class, 'No adapter runs stdClass. Swerve runs function (ClientRequest $request): void');
});

it('names the package for a known framework\'s handler', function () {
    eval('namespace Symfony\Component\HttpKernel; interface HttpKernelInterface {} final class TestKernel implements HttpKernelInterface {}');
    expect(fn () => Handlers::resolve(new Symfony\Component\HttpKernel\TestKernel(), $this->mode))
        ->toThrow(LogicException::class, 'No adapter runs Symfony\Component\HttpKernel\TestKernel: install phasync/swerve-symfony');
});

it('runs a registered adapter, refuses two that both take a handler, and lets one refuse a mode', function () {
    $adapter = new class implements Adapter {
        public function canHandle(object $handler, Mode $mode): bool
        {
            if ($handler instanceof ArrayObject && $mode instanceof AsyncMode) {
                throw new LogicException('not in Async mode');
            }

            return $handler instanceof ArrayObject || $handler instanceof Closure;
        }

        public function handle(ClientRequest $request, object $handler): void
        {
        }
    };
    Handlers::register($adapter);
    expect(Handlers::resolve(new ArrayObject(), $this->mode))->toBe($adapter)
        ->and(fn () => Handlers::resolve(new ArrayObject(), AsyncMode::serve(null, new stdClass())))->toThrow(LogicException::class, 'not in Async mode')
        ->and(fn () => Handlers::resolve(static function (ClientRequest $r): void {}, $this->mode))
        ->toThrow(LogicException::class, 'Several adapters run the closure function (Swerve\ClientRequest $r): void');
});
