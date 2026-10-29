<?php

/*
 * In-process tests of Container: lifetimes, failures, and the limit within one process (the
 * workers' side is ContainerTest). A limit needs APCu enabled for the CLI here, as UnitCacheTest.
 */

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Swerve\Container;
use Swerve\ContainerException;
use Swerve\Lifetime;
use Swerve\ServiceNotFoundException;

/** An id of its own for each test: the container is the process's. */
function ct_id(string $name): string
{
    return "unit.$name." . bin2hex(random_bytes(4));
}

test('Scoped: one instance per Scope, another in another Scope; Transient: a new one each time; Singleton: one for the worker', function () {
    $c         = Container::instance();
    [$s, $t, $w] = [ct_id('scoped'), ct_id('transient'), ct_id('singleton')];
    $c->add($s, fn () => new stdClass());
    $c->add($t, fn () => new stdClass(), lifetime: Lifetime::Transient);
    $c->add($w, fn () => new stdClass(), lifetime: Lifetime::Singleton);
    phasync::run(function () use ($c, $s, $t, $w) {
        [$a, $b, $wa] = (new Scope())->run(fn () => [$c->get($s), $c->get($s), $c->get($w)]);
        [$other, $wb] = (new Scope())->run(fn () => [$c->get($s), $c->get($w)]);
        expect($a)->toBe($b)->not->toBe($other);
        expect($wa)->toBe($wb);
        expect($c->get($t))->not->toBe($c->get($t));
    });
    expect([$c->has($s), $c->has('unit.never-added')])->toBe([true, false]);
});

test('coroutines of one request that ask at once share one instance: the factory runs once', function () {
    $c     = Container::instance();
    $id    = ct_id('concurrent');
    $built = 0;
    $c->add($id, function () use (&$built) {
        ++$built;
        phasync::sleep(0.05); // connecting

        return new stdClass();
    });
    phasync::run(fn () => (new Scope())->run(function () use ($c, $id) {
        $first  = phasync::go(fn () => $c->get($id));
        $second = phasync::go(fn () => $c->get($id));
        expect(phasync::await($first))->toBe(phasync::await($second));
    }));
    expect($built)->toBe(1);
});

test('a factory that throws is a ContainerException with its exception as the previous, and the next get() tries again; one returning no object too', function () {
    $c     = Container::instance();
    $id    = ct_id('failing');
    $tries = 0;
    $c->add($id, function () use (&$tries) {
        if (1 === ++$tries) {
            throw new RuntimeException('connection refused');
        }

        return new stdClass();
    });
    phasync::run(fn () => (new Scope())->run(function () use ($c, $id) {
        try {
            $c->get($id);
            expect(false)->toBeTrue();
        } catch (ContainerException $e) {
            expect($e)->toBeInstanceOf(ContainerExceptionInterface::class);
            expect($e->getPrevious()->getMessage())->toBe('connection refused');
        }
        expect($c->get($id))->toBeInstanceOf(stdClass::class);
    }));
    $none = ct_id('scalar');
    $c->add($none, fn () => 42);
    expect(fn () => phasync::run(fn () => $c->get($none)))->toThrow(ContainerException::class, 'returned int, not an object');
});

test('misuse fails loudly: an id not added, an id added twice, a limit below 1', function () {
    $c  = Container::instance();
    $id = ct_id('twice');
    $c->add($id, fn () => new stdClass());
    expect(fn () => $c->get('unit.nobody'))->toThrow(ServiceNotFoundException::class, 'No service unit.nobody');
    expect(new ServiceNotFoundException())->toBeInstanceOf(NotFoundExceptionInterface::class);
    expect(fn () => $c->add($id, fn () => new stdClass()))->toThrow(LogicException::class, 'is added already');
    expect(fn () => $c->add(ct_id('zero'), fn () => new stdClass(), 0))->toThrow(ValueError::class, 'at least 1');
});

test('a limit: get() waits while all are out, and goes on when one is garbage', function () {
    if (!extension_loaded('apcu') || !apcu_enabled()) {
        test()->markTestSkipped('needs apc.enable_cli=1 in this process');
    }
    $c  = Container::instance();
    $id = ct_id('limited');
    $c->add($id, fn () => new stdClass(), 2, Lifetime::Transient);
    phasync::run(function () use ($c, $id) {
        $held   = [$c->get($id), $c->get($id)];
        $third  = phasync::go(fn () => $c->get($id));
        phasync::sleep(0.05);
        expect($third->isTerminated())->toBeFalse(); // two are out
        array_pop($held);                            // one is garbage: its slot is back
        $start = microtime(true);
        expect(phasync::await($third))->toBeInstanceOf(stdClass::class);
        expect(microtime(true) - $start)->toBeLessThan(0.5); // woken by the release, not the retry
    });
});

test('a Scoped instance gives its slot back when its Scope ends', function () {
    if (!extension_loaded('apcu') || !apcu_enabled()) {
        test()->markTestSkipped('needs apc.enable_cli=1 in this process');
    }
    $c  = Container::instance();
    $id = ct_id('scoped-limit');
    $c->add($id, fn () => new stdClass(), 1);
    phasync::run(function () use ($c, $id) {
        $request = fn () => (new Scope())->run(fn () => spl_object_id($c->get($id)) && $c->get($id) === $c->get($id));
        expect($request())->toBeTrue();   // one slot, however often the request asks
        expect($request())->toBeTrue();   // the first request's Scope ended: its slot is back
    });
});

test('tryGet() does not wait for a slot: null and $success false while all are out; the instance once one is back', function () {
    if (!extension_loaded('apcu') || !apcu_enabled()) {
        test()->markTestSkipped('needs apc.enable_cli=1 in this process');
    }
    $c  = Container::instance();
    $id = ct_id('try');
    $c->add($id, fn () => new stdClass(), 1);
    phasync::run(function () use ($c, $id) {
        $held = (new Scope())->run(fn () => $c->tryGet($id, $success) && $success ? 'got it' : 'no');
        expect($held)->toBe('got it');
        $scope = new Scope();
        $first = $scope->run(fn () => $c->get($id));
        (new Scope())->run(function () use ($c, $id) {
            expect($c->tryGet($id, $success))->toBeNull();
            expect($success)->toBeFalse();
        });
        expect($scope->run(function () use ($c, $id, &$success) {
            return $c->tryGet($id, $success);
        }))->toBe($first); // its request has one already
        expect($success)->toBeTrue();
        unset($first, $scope);
        (new Scope())->run(function () use ($c, $id) {
            expect($c->tryGet($id, $success))->toBeInstanceOf(stdClass::class);
            expect($success)->toBeTrue();
        });
    });
});
