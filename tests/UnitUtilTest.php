<?php

/*
 * Pure unit tests of the command line (Args, Option, Flag, Argument, Address) and of System's
 * helpers that need no server or child process.
 */

use Swerve\CLI\Address;
use Swerve\Util\SealedObject;
use Swerve\CLI\Args;
use Swerve\CLI\Argument;
use Swerve\CLI\Flag;
use Swerve\CLI\Option;
use Swerve\Util\System;

function unitArgs(array $argv): Args
{
    return (new Args($argv))
        ->add('workers', new Option('w', 'workers', 'Worker count', '2', validator: fn ($v) => ctype_digit($v) ? null : 'a number required'))
        ->add('http', new Option('', 'http', 'Listen address', multiple: true))
        ->add('name', new Option('n', '', 'Name', required: true, placeholder: 'name'))
        ->add('verbose', new Flag('v', 'verbose', 'Verbose', multiple: true))
        ->add('quiet', new Flag('q', 'quiet', 'Quiet'));
}

function unitTmpPath(): string
{
    return sys_get_temp_dir() . '/swerve-unit-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sock';
}

// the code under test silences expected failures with @, which Pest still reports as warnings
function unitQuiet(callable $fn): void
{
    set_error_handler(static fn () => true);
    try {
        $fn();
    } finally {
        restore_error_handler();
    }
}

// --- Option, Flag, Argument --------------------------------------------------------------

test('an option rejects a short name longer than one character, and a default when required', function () {
    expect(fn () => new Option('ab'))->toThrow(InvalidArgumentException::class, 'Short option');
    expect(fn () => new Option('a', 'aa', required: true, default: 'x'))->toThrow(InvalidArgumentException::class, 'default');
    expect(fn () => new Flag('vv'))->toThrow(InvalidArgumentException::class, 'Short flag');
});

test('an option gives its value from the short or long name, else its default', function () {
    $o = new Option('w', 'workers', default: '2');
    expect($o->getValue(['w' => '4']))->toBe('4');
    expect($o->getValue(['workers' => '8']))->toBe('8');
    expect($o->getValue([]))->toBe('2');
    expect($o->isDefault([]))->toBeTrue();
    expect($o->isDefault(['workers' => '2']))->toBeFalse();
});

test('a multiple option collects every value, or its default as a list', function () {
    $o = new Option('l', 'listen', default: 'x', multiple: true);
    expect($o->getValue(['l' => ['a', 'b'], 'listen' => 'c']))->toBe(['a', 'b', 'c']);
    expect($o->getValue([]))->toBe(['x']);
    expect((new Option('l', multiple: true))->getValue([]))->toBe([]);
});

test('an option reports a missing value, a rejected value, and a repeat it does not allow', function () {
    $o = new Option('w', 'workers', validator: fn ($v) => $v === 'bad' ? 'nope' : null);
    expect($o->isInvalid(['w' => false]))->toContain('Value required for option: -w');
    expect($o->isInvalid(['workers' => false]))->toContain('--workers');
    expect($o->isInvalid(['workers' => 'bad']))->toContain('Illegal value for option: --workers: nope');
    expect($o->isInvalid(['w' => ['1', 'bad']]))->toContain('-w: nope');
    expect($o->isInvalid(['w' => '1', 'workers' => '2']))->toContain('Multiple values not permitted');
    expect($o->isInvalid(['w' => '1']))->toBeNull();
    expect((new Option('w', required: true))->isInvalid([]))->toContain('Value required');
});

test('a flag counts its occurrences and refuses a repeat unless multiple', function () {
    $f = new Flag('v', 'verbose');
    expect($f->getValue([]))->toBe(0);
    expect($f->getValue(['v' => [false, false], 'verbose' => false]))->toBe(3);
    expect($f->isInvalid(['v' => false]))->toBeNull();
    expect($f->isInvalid(['v' => false, 'verbose' => false]))->toContain('Multiple flags not allowed for: -v --verbose');
    expect($f->isInvalid(['v' => [false, false]]))->not->toBeNull();
    expect((new Flag('v', multiple: true))->isInvalid(['v' => [false, false]]))->toBeNull();
});

test('an argument validates with its validator, and is valid without one', function () {
    expect((new Argument('file', 'A file'))->isInvalid('x'))->toBeNull();
    expect((new Argument('file', 'A file', validator: fn ($v) => 'bad ' . $v))->isInvalid('x'))->toBe('bad x');
});

// --- Args --------------------------------------------------------------------------------

test('Args parses attached and separate values, long and short', function () {
    $a = unitArgs(['--workers=4', '-n', 'web']);
    expect($a->isInvalid())->toBeNull();
    expect($a->workers)->toBe('4');
    expect($a->name)->toBe('web');
    expect(unitArgs(['-w4', '-nx'])->workers)->toBe('4');
    expect(unitArgs(['--workers', '5', '-n', 'x'])->workers)->toBe('5');
    expect(unitArgs(['-w=6', '-n', 'x'])->workers)->toBe('6');
});

test('Args gives defaults for options not given, and isDefault tells', function () {
    $a = unitArgs(['-n', 'x']);
    expect($a->workers)->toBe('2');
    expect($a->isDefault('workers'))->toBeTrue();
    expect($a->http)->toBe([]);
    expect(unitArgs(['-w', '2', '-n', 'x'])->isDefault('workers'))->toBeFalse();
    expect(fn () => $a->isDefault('verbose'))->toThrow(LogicException::class, 'Flags have no default');
    expect(fn () => $a->isDefault('nope'))->toThrow(LogicException::class, 'not defined');
});

test('Args groups flags, counts them, and takes an option at the end of a group', function () {
    $a = unitArgs(['-vv', '-qvw', '3', '-n', 'x']);
    expect($a->verbose)->toBe(3);
    expect($a->quiet)->toBe(1);
    expect($a->workers)->toBe('3');
    expect($a->isInvalid())->toBeNull();
});

test('Args refuses a flag given twice unless it is multiple', function () {
    expect(unitArgs(['-q', '--quiet', '-n', 'x'])->isInvalid())->toContain('Multiple flags not allowed');
    expect(unitArgs(['-qq', '-n', 'x'])->isInvalid())->toContain('Multiple flags not allowed');
    expect(unitArgs(['-v', '--verbose', '-n', 'x'])->isInvalid())->toBeNull();
});

test('Args refuses an option given twice unless it is multiple, which keeps the order', function () {
    expect(unitArgs(['-w1', '-w2', '-n', 'x'])->isInvalid())->toContain('Multiple values not permitted');
    $a = unitArgs(['--http=8080', '--http', ':9090', '--http=unix:/a.sock', '-n', 'x']);
    expect($a->isInvalid())->toBeNull();
    expect($a->http)->toBe(['8080', ':9090', 'unix:/a.sock']);
});

test('Args reports unknown options and flags', function () {
    expect(unitArgs(['--nope'])->isInvalid())->toBe('Unknown option: --nope');
    expect(unitArgs(['-z'])->isInvalid())->toBe('Unknown flag: -z');
    expect(unitArgs(['-vz'])->isInvalid())->toBe('Unknown flag: -z in -vz');
});

test('Args reports a missing option value, an empty one, and a value given to a flag', function () {
    expect(unitArgs(['--workers'])->isInvalid())->toContain('Value required for option: --workers=<value>');
    expect(unitArgs(['--workers='])->isInvalid())->toContain('Value required for option: --workers');
    expect(unitArgs(['-w'])->isInvalid())->toContain('Value required for option: -w <value>');
    expect(unitArgs(['--verbose=1'])->isInvalid())->toBe('--verbose takes no value');
});

test('Args reports a required option left out, and an illegal value', function () {
    expect(unitArgs([])->isInvalid())->toContain('Value required for option: -n');
    expect(unitArgs(['-n', 'x', '-w', 'many'])->isInvalid())->toBe('Illegal value for option: -w: a number required');
});

test('Args ends the options at --, and treats a lone - as an argument', function () {
    $a = (new Args(['-n', 'x', '--', '--workers', '-v']))
        ->add('name', new Option('n', required: true))
        ->add('first', new Argument('first', 'First', 'd'))
        ->add('second', new Argument('second', 'Second', 'd'));
    expect($a->isInvalid())->toBeNull();
    expect($a->first)->toBe('--workers');
    expect($a->second)->toBe('-v');
    $b = (new Args(['-']))->add('file', new Argument('file', 'A file', 'd'));
    expect($b->file)->toBe('-');
});

test('Args takes arguments in any order among options, with defaults, and counts them', function () {
    $a = (new Args(['a.php', '-v', 'extra']))
        ->add('verbose', new Flag('v'))
        ->add('script', new Argument('script', 'Script', 'swerve.php'));
    expect($a->script)->toBe('a.php');
    expect($a->isInvalid())->toBe('Unknown argument: extra');
    $none = (new Args([]))->add('script', new Argument('script', 'Script', 'swerve.php'));
    expect($none->script)->toBe('swerve.php');
    expect($none->isDefault('script'))->toBeTrue();
    expect($none->isInvalid())->toBeNull();
    $req = (new Args([]))->add('script', new Argument('script', 'Script'));
    expect($req->isInvalid())->toBe('Required argument: script');
    $val = (new Args(['x']))->add('script', new Argument('script', 'Script', validator: fn () => 'refused'));
    expect($val->isInvalid())->toBe('refused');
});

test('Args refuses a name or a short or long option used twice, and an undefined name', function () {
    $a = (new Args([]))->add('a', new Option('x', 'xx'));
    expect(fn () => $a->add('a', new Flag('y')))->toThrow(InvalidArgumentException::class, 'already added');
    expect(fn () => $a->add('b', new Flag('x')))->toThrow(InvalidArgumentException::class, '-x already used for a');
    expect(fn () => $a->add('c', new Option('', 'xx')))->toThrow(InvalidArgumentException::class, '--xx already used for a');
    expect($a->add('d', new Flag('', 'dd'))->add('e', new Flag('', 'ee')))->toBe($a);
    expect(fn () => $a->nope)->toThrow(LogicException::class, 'not defined');
    expect(isset($a->a))->toBeTrue();
    expect(isset($a->nope))->toBeFalse();
});

test('Args reads the process arguments when given none', function () {
    $saved = $_SERVER['argv'];
    $_SERVER['argv'] = ['swerve', '-q'];
    try {
        $a = (new Args())->add('quiet', new Flag('q'));
        expect($a->quiet)->toBe(1);
    } finally {
        $_SERVER['argv'] = $saved;
    }
});

test('Args lists its arguments for --help, aligned, with sections and defaults', function () {
    $a = (new Args([]))
        ->section('Server')
        ->add('workers', new Option('w', 'workers', 'Worker count', '2'))
        ->add('quiet', new Flag('q', '', 'Quiet'))
        ->add('script', new Argument('script', 'Script', 'swerve.php'))
        ->add('file', new Argument('file', 'File'));
    $list = $a->getArgumentList();
    expect($list)->toContain('Server:');
    expect($list)->toContain('-w, --workers=<value>');
    expect($list)->toContain('Worker count (default: 2)');
    expect($list)->toContain('[script]');
    expect($list)->toContain('<file>');
    expect($a->getShortArgumentList())->toBe('[options] [script] <file>');
});

// --- Address -----------------------------------------------------------------------------

test('Address normalizes ports, interfaces, IPv4 and IPv6', function (string $given, string $expected) {
    expect(Address::normalize($given))->toBe($expected);
})->with([
    'a bare port is local only'  => ['8080', '127.0.0.1:8080'],
    ':port is every interface'   => [':8080', '0.0.0.0:8080'],
    'ipv4'                       => ['10.1.2.3:80', '10.1.2.3:80'],
    'any ipv4'                   => ['0.0.0.0:443', '0.0.0.0:443'],
    'ipv6 loopback'              => ['[::1]:8080', '[::1]:8080'],
    'ipv6 any'                   => ['[::]:8080', '[::]:8080'],
    'ipv6 full'                  => ['[2001:db8::1]:1', '[2001:db8::1]:1'],
    'the lowest port'            => ['1', '127.0.0.1:1'],
    'the highest port'           => ['65535', '127.0.0.1:65535'],
    'a host name is resolved'    => ['localhost:8080', '127.0.0.1:8080'],
]);

test('Address refuses bad ports', function (string $given) {
    expect(fn () => Address::normalize($given))->toThrow(InvalidArgumentException::class, 'port');
})->with(['0', '65536', '99999999999', '0080', '127.0.0.1:0', '127.0.0.1:', '127.0.0.1:-1', '127.0.0.1:80a', ':']);

test('Address refuses malformed addresses and IPv4 inside brackets', function () {
    foreach (['', 'host', '[::1]', 'a:b:80', '::1:80', '[::1', '::1]:80', '8080 ', '127.0.0.1:80:90'] as $given) {
        expect(fn () => Address::normalize($given))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => Address::normalize('[1.2.3.4]:80'))->toThrow(InvalidArgumentException::class, 'is not an IPv6 address');
    expect(fn () => Address::normalize('[nonsense]:80'))->toThrow(InvalidArgumentException::class, 'is not an IPv6 address');
    expect(fn () => Address::normalize('no-such-host.invalid:80'))->toThrow(InvalidArgumentException::class, "can't resolve no-such-host.invalid");
});

test('Address normalizes unix: sockets, absolute and relative', function () {
    expect(Address::normalize('unix:/run/swerve.sock'))->toBe('unix:/run/swerve.sock');
    expect(Address::normalize('unix:///run/swerve.sock'))->toBe('unix:/run/swerve.sock');
    expect(Address::normalize('/run/swerve.sock'))->toBe('unix:/run/swerve.sock');
    expect(Address::normalize('unix:rel/s.sock'))->toBe('unix:' . getcwd() . '/rel/s.sock');
});

test('Address refuses an empty or directory socket path, and one longer than sun_path', function () {
    expect(fn () => Address::normalize('unix:'))->toThrow(InvalidArgumentException::class, 'path to a socket file');
    expect(fn () => Address::normalize('unix:///'))->toThrow(InvalidArgumentException::class, 'path to a socket file');
    expect(fn () => Address::normalize('/run/dir/'))->toThrow(InvalidArgumentException::class, 'path to a socket file');
    $ok = '/' . str_repeat('a', 106);
    expect(Address::normalize($ok))->toBe("unix:$ok");
    expect(fn () => Address::normalize($ok . 'a'))->toThrow(InvalidArgumentException::class, '107 bytes');
});

// --- System ------------------------------------------------------------------------------

test('System counts at least one CPU and reports whether ext-sockets is usable', function () {
    expect(System::getCPUCount())->toBeGreaterThanOrEqual(1);
    expect(System::hasSockets())->toBe(function_exists('socket_create') && defined('SOCK_CLOEXEC'));
});

test('System names signals, and SIG? for an unknown one', function () {
    expect(System::signalName(0))->toBe('SIG?');
    expect(System::signalName(9999))->toBe('SIG?');
    expect(System::signalName(-1))->toBe('SIG?');
})->group('signals');

test('System names the signals PHP defines constants for', function () {
    expect(System::signalName(SIGTERM))->toBe('SIGTERM');
    expect(System::signalName(SIGKILL))->toBe('SIGKILL');
    expect(System::signalName(SIGINT))->toBe('SIGINT');
    expect(System::signalName(SIGUSR1))->toBe('SIGUSR1');
})->skip(!defined('SIGTERM'), 'needs ext-pcntl');

test('System listens on a unix socket once, and unlinkSocket removes the file', function () {
    $path = unitTmpPath();
    $a    = System::listen("unix:$path");
    try {
        expect(filetype($path))->toBe('socket');
        expect(fileperms($path) & 0777)->toBe(0666);
        expect(stream_get_meta_data($a)['blocked'])->toBeFalse();
        expect(System::listen("unix:$path"))->toBe($a);
        $client = stream_socket_client("unix://$path", $errno, $errstr, 1);
        expect(is_resource($client))->toBeTrue();
        fclose($client);
    } finally {
        fclose($a);
        System::unlinkSocket("unix:$path");
    }
    expect(file_exists($path))->toBeFalse();
    unitQuiet(fn () => System::unlinkSocket("unix:$path")); // already gone: no error
});

test('System refuses a unix path that is a file, or where something listens', function () {
    $file = unitTmpPath();
    file_put_contents($file, 'x');
    try {
        expect(fn () => System::listen("unix:$file"))->toThrow(RuntimeException::class, 'is not a socket');
    } finally {
        unlink($file);
    }
    $path   = unitTmpPath();
    $server = stream_socket_server("unix://$path", $errno, $errstr);
    try {
        expect(fn () => System::listen("unix:$path"))->toThrow(RuntimeException::class, 'already in use');
    } finally {
        fclose($server);
        @unlink($path);
    }
});

test('System replaces a stale unix socket file', function () {
    $path  = unitTmpPath();
    $stale = stream_socket_server("unix://$path", $errno, $errstr);
    fclose($stale); // PHP leaves the file behind
    expect(filetype($path))->toBe('socket');
    unitQuiet(function () use ($path, &$listener) {
        $listener = System::listen("unix:$path");
    });
    try {
        expect(is_resource($listener))->toBeTrue();
    } finally {
        fclose($listener);
        System::unlinkSocket("unix:$path");
    }
});

test('System makes a connected socket pair', function () {
    [$a, $b] = System::socketPair();
    try {
        fwrite($a, "ping\n");
        expect(fgets($b))->toBe("ping\n");
        fwrite($b, "pong\n");
        expect(fgets($a))->toBe("pong\n");
    } finally {
        fclose($a);
        fclose($b);
    }
});

test('a port is plain decimal digits: no whitespace, sign or leading zero', function () {
    foreach (['127.0.0.1: 80', '127.0.0.1:80 ', '127.0.0.1:+80', '127.0.0.1:080', ':0', ':65536'] as $bad) {
        expect(fn () => Swerve\CLI\Address::normalize($bad))->toThrow(InvalidArgumentException::class);
    }
    expect(Swerve\CLI\Address::normalize('127.0.0.1:65535'))->toBe('127.0.0.1:65535');
});

test('empty IPv6 brackets are refused as an address, not looked up as a host name', function () {
    expect(fn () => Swerve\CLI\Address::normalize('[]:80'))->toThrow(InvalidArgumentException::class, 'host:port');
});

// --- SealedObject ----------------------------------------------------------------------------

test('SealedObject: nested objects are sealed too, the same instance every time, and reading looks like stdClass', function () {
    $message = SealedObject::seal(json_decode('{"end":true,"user":{"name":"a","tags":[{"t":1},2]},"empty":{}}'));
    expect($message)->toBeInstanceOf(SealedObject::class);
    expect($message->end)->toBeTrue();
    expect(isset($message->end))->toBeTrue();
    expect(isset($message->missing))->toBeFalse();
    expect($message->missing ?? 'default')->toBe('default');
    expect($message->user)->toBeInstanceOf(SealedObject::class);
    expect($message->user)->toBe($message->user);
    expect($message->user->name)->toBe('a');
    expect($message->user->tags[0])->toBeInstanceOf(SealedObject::class);
    expect($message->user->tags[0]->t)->toBe(1);
    expect($message->user->tags[1])->toBe(2);
    expect($message->empty)->toBeInstanceOf(SealedObject::class); // {} stays an object, unlike with assoc = true
    expect(array_keys(iterator_to_array($message)))->toBe(['end', 'user', 'empty']);
    expect(json_encode($message))->toBe('{"end":true,"user":{"name":"a","tags":[{"t":1},2]},"empty":{}}');
});

test('SealedObject: nothing can be attached, changed or removed, also under the names of its own members', function () {
    $message = SealedObject::seal(json_decode('{"a":1,"inner":"x","sealed":"y"}'));
    expect($message->inner)->toBe('x');
    expect($message->sealed)->toBe('y');
    foreach (['a', 'new', 'inner', 'sealed'] as $name) {
        expect(fn () => $message->$name = 2)->toThrow(LogicException::class);
        expect(function () use ($message, $name) { unset($message->$name); })->toThrow(LogicException::class);
    }
    expect($message->a)->toBe(1);
    // What json_encode() takes is a copy
    $copy = $message->jsonSerialize();
    $copy->a = 2;
    expect($message->a)->toBe(1);
});

test('SealedObject: a list at the top keeps its objects sealed, scalars pass through', function () {
    $list = SealedObject::seal(json_decode('[{"a":1},[{"b":2}],"s",3]'));
    expect($list[0])->toBeInstanceOf(SealedObject::class);
    expect($list[1][0]->b)->toBe(2);
    expect([$list[2], $list[3]])->toBe(['s', 3]);
    expect(SealedObject::seal('{}'))->toBe('{}');
});

test('SealedObject: reading a missing property warns, as stdClass does', function () {
    $message = SealedObject::seal(json_decode('{"a":1}'));
    $seen = null;
    set_error_handler(function (int $no, string $str) use (&$seen) { $seen = $str; return true; });
    try {
        expect($message->nope)->toBeNull();
    } finally {
        restore_error_handler();
    }
    expect($seen)->toContain('Undefined property')->toContain('$nope');
});
