<?php

use Swerve\AsyncMode;
use Swerve\ClassicMode;
use Swerve\PersistentMode;
use Swerve\Util\Router;

/** An application directory: public/ with files, a router outside it; the working directory. */
function mode_app(): string
{
    $dir = temp_path(true);
    mkdir("$dir/public/blog/2024", 0777, true);
    mkdir("$dir/public/admin");
    mkdir("$dir/public/docs");
    mkdir("$dir/public/empty");
    mkdir("$dir/public/.git");
    mkdir("$dir/public/.well-known");
    mkdir("$dir/app");
    file_put_contents("$dir/public/index.php", '<?php');
    file_put_contents("$dir/public/test.php", '<?php');
    file_put_contents("$dir/public/TEST.PHP", '<?php');
    file_put_contents("$dir/public/lib.inc", '<?php');
    file_put_contents("$dir/public/admin/index.php", '<?php');
    file_put_contents("$dir/public/docs/index.html", 'docs');
    file_put_contents("$dir/public/style.css", 'a{}');
    file_put_contents("$dir/public/a b.txt", 'space');
    file_put_contents("$dir/public/.env", 'SECRET=1');
    file_put_contents("$dir/public/.git/config", 'x');
    file_put_contents("$dir/public/.well-known/security.txt", 'contact');
    file_put_contents("$dir/app/front.php", '<?php');
    file_put_contents("$dir/secret.txt", 'outside');
    file_put_contents("$dir/secret.php", '<?php');
    symlink("$dir/secret.txt", "$dir/public/link.txt");
    symlink("$dir/secret.php", "$dir/public/link.php");
    chdir($dir);

    return realpath($dir);
}

describe('modes', function () {
    beforeEach(fn () => $this->cwd = getcwd());
    afterEach(fn () => chdir($this->cwd));

    it('resolves paths relative to the working directory, and the router defaults to index.php in the public root', function () {
        $dir     = mode_app();
        $classic = ClassicMode::serve('public');
        expect($classic->publicRoot)->toBe("$dir/public")
            ->and($classic->router)->toBe("$dir/public/index.php")
            ->and(ClassicMode::serve('public', 'app/front.php')->router)->toBe("$dir/app/front.php")
            ->and(ClassicMode::serve("$dir/public", "$dir/app/front.php")->router)->toBe("$dir/app/front.php")
            ->and(ClassicMode::serve('', 'public/index.php')->publicRoot)->toBe($dir)
            ->and(new ClassicMode('public'))->toEqual($classic);
    });

    it('fails when the public root or the router does not exist', function () {
        $dir = mode_app();
        expect(fn () => ClassicMode::serve('nope'))->toThrow(InvalidArgumentException::class, "The public root nope is not a directory (relative to $dir)")
            ->and(fn () => ClassicMode::serve('app'))->toThrow(InvalidArgumentException::class, "The router $dir/app/index.php is not a file")
            ->and(fn () => ClassicMode::serve('public', 'app'))->toThrow(InvalidArgumentException::class, 'The router app is not a file')
            ->and(fn () => PersistentMode::serve('nope', new stdClass()))->toThrow(InvalidArgumentException::class);
    });

    it('has no public root when given null (Persistent, Async)', function () {
        mode_app();
        $handler = static fn () => null;
        expect(PersistentMode::serve(null, $handler)->publicRoot)->toBeNull()
            ->and(AsyncMode::serve(null, $handler)->handler)->toBe($handler);
    });

    it('adds async routes immutably, in order', function () {
        mode_app();
        $a     = static fn () => 1;
        $b     = static fn () => 2;
        $base  = ClassicMode::serve('public');
        $mode  = $base->withAsync('/ws/', $a)->withAsync('\.scss$', $b);
        expect($base->asyncRoutes())->toBe([])
            ->and($mode->asyncRoutes())->toBe([['/ws/', "\x01^/ws/\x01Du", $a], ['\.scss$', "\x01\\.scss$\x01Du", $b]]);
    });

    it('refuses an empty, invalid, delimiter-carrying or repeated pattern', function () {
        mode_app();
        $mode = ClassicMode::serve('public')->withAsync('/ws/', new stdClass());
        expect(fn () => $mode->withAsync('', new stdClass()))->toThrow(InvalidArgumentException::class, 'the pattern is empty')
            ->and(fn () => $mode->withAsync('/a(', new stdClass()))->toThrow(InvalidArgumentException::class, "'/a(' is not a valid regular expression: ")
            ->and(fn () => $mode->withAsync("/a\x01", new stdClass()))->toThrow(InvalidArgumentException::class, 'delimiter')
            ->and(fn () => $mode->withAsync('/ws/', new stdClass()))->toThrow(InvalidArgumentException::class, "'/ws/' was added already");
    });
});

describe('routing', function () {
    beforeEach(fn () => $this->cwd = getcwd());
    afterEach(fn () => chdir($this->cwd));

    it('routes Classic as try_files $uri $uri/ /index.php', function () {
        $dir    = mode_app();
        $p      = "$dir/public";
        $router = new Router(ClassicMode::serve('public'));
        $index  = [Router::SCRIPT, "$p/index.php", '/index.php', null];
        expect($router->route('/style.css'))->toBe([Router::STATIC, "$p/style.css"])
            ->and($router->route('/style.css?v=2'))->toBe([Router::STATIC, "$p/style.css"])
            ->and($router->route('/a%20b.txt'))->toBe([Router::STATIC, "$p/a b.txt"])
            ->and($router->route('/test.php'))->toBe([Router::SCRIPT, "$p/test.php", '/test.php', null])
            ->and($router->route('/test.php/'))->toBe([Router::SCRIPT, "$p/test.php", '/test.php', '/'])
            ->and($router->route('/index.php/2024/hello'))->toBe([Router::SCRIPT, "$p/index.php", '/index.php', '/2024/hello'])
            ->and($router->route('/test'))->toBe($index)                       // no extensionless mapping
            ->and($router->route('/'))->toBe($index)
            ->and($router->route('/?p=1'))->toBe($index)
            ->and($router->route('/admin/'))->toBe([Router::SCRIPT, "$p/admin/index.php", '/admin/index.php', null])
            ->and($router->route('/admin'))->toBe([Router::REDIRECT, '/admin/'])
            ->and($router->route('/admin?x=1'))->toBe([Router::REDIRECT, '/admin/?x=1'])
            ->and($router->route('/docs/'))->toBe([Router::STATIC, "$p/docs/index.html"])
            ->and($router->route('/docs'))->toBe([Router::REDIRECT, '/docs/'])
            ->and($router->route('/empty/'))->toBe($index)                     // never a listing
            ->and($router->route('/empty'))->toBe($index)
            ->and($router->route('/blog/2024/'))->toBe($index)
            ->and($router->route('/2024/hello/'))->toBe($index)
            ->and($router->route('/style.css/'))->toBe($index)                 // a static file followed by /
            ->and($router->route('/style.css/x'))->toBe($index);
    });

    it('never serves or runs dotfiles, PHP source, or anything outside the public root', function () {
        $dir    = mode_app();
        $p      = "$dir/public";
        $router = new Router(ClassicMode::serve('public'));
        $index  = [Router::SCRIPT, "$p/index.php", '/index.php', null];
        expect($router->route('/.env'))->toBe($index)
            ->and($router->route('/.git/config'))->toBe($index)
            ->and($router->route('/x/../.env'))->toBe($index)
            ->and($router->route('/.well-known/security.txt'))->toBe([Router::STATIC, "$p/.well-known/security.txt"])
            ->and($router->route('/lib.inc'))->toBe($index)
            ->and($router->route('/TEST.PHP'))->toBe($index)
            ->and($router->route('/link.txt'))->toBe($index)
            ->and($router->route('/link.php'))->toBe($index);
    });

    it('normalises the path, and rejects control characters, invalid UTF-8 and escaping the root', function () {
        $dir    = mode_app();
        $p      = "$dir/public";
        $router = new Router(ClassicMode::serve('public'));
        expect($router->route('//docs//./index.html'))->toBe([Router::STATIC, "$p/docs/index.html"])
            ->and($router->route('/blog/../style.css'))->toBe([Router::STATIC, "$p/style.css"])
            ->and($router->route('/%2e%2e/secret.txt'))->toBe([Router::BAD])
            ->and($router->route('/../secret.txt'))->toBe([Router::BAD])
            ->and($router->route('/a%00b'))->toBe([Router::BAD])
            ->and($router->route('/a%0Ab'))->toBe([Router::BAD])
            ->and($router->route('/%C3%28'))->toBe([Router::BAD])
            ->and($router->route('/%C3%A6'))->toBe([Router::SCRIPT, "$p/index.php", '/index.php', null])
            ->and($router->route('http://example.com/style.css'))->toBe([Router::STATIC, "$p/style.css"])
            ->and($router->route('example.com:443'))->toBe([Router::BAD])
            ->and($router->route('*'))->toBe([Router::SCRIPT, "$p/index.php", '/index.php', null]);
    });

    it('names a router outside the public root by its file name', function () {
        $dir = mode_app();
        expect((new Router(ClassicMode::serve('public', 'app/front.php')))->route('/x'))->toBe([Router::SCRIPT, "$dir/app/front.php", '/front.php', null]);
    });

    it('routes Persistent and Async: files, index.html, and the handler for the rest', function () {
        $dir     = mode_app();
        $p       = "$dir/public";
        $handler = static fn () => null;
        foreach ([PersistentMode::serve('public', $handler), AsyncMode::serve('public', $handler)] as $mode) {
            $router = new Router($mode);
            expect($router->route('/style.css'))->toBe([Router::STATIC, "$p/style.css"])
                ->and($router->route('/test.php'))->toBe([Router::APP])
                ->and($router->route('/test.php/'))->toBe([Router::APP])
                ->and($router->route('/admin/'))->toBe([Router::APP])
                ->and($router->route('/admin'))->toBe([Router::APP])
                ->and($router->route('/docs'))->toBe([Router::REDIRECT, '/docs/'])
                ->and($router->route('/docs/'))->toBe([Router::STATIC, "$p/docs/index.html"])
                ->and($router->route('/'))->toBe([Router::APP]);
        }
        expect((new Router(AsyncMode::serve(null, $handler)))->route('/style.css'))->toBe([Router::APP]);
    });

    it('tries the async routes first, in order: anchored when they start with /', function () {
        mode_app();
        $h      = new stdClass();
        $router = new Router(ClassicMode::serve('public')
            ->withAsync('/ws/', $h)
            ->withAsync('\.css$', $h)
            ->withAsync('^(?i)/admin/', $h)
            ->withAsync('/ws/chat', $h));
        expect($router->route('/ws/chat'))->toBe([Router::ASYNC, 0])
            ->and($router->route('/style.css'))->toBe([Router::ASYNC, 1])   // before static files
            ->and($router->route('/x/style.css'))->toBe([Router::ASYNC, 1])
            ->and($router->route('/style.css?x'))->toBe([Router::ASYNC, 1])
            ->and($router->route("/style.css\n"))->toBe([Router::BAD])
            ->and($router->route('/ADMIN/x'))->toBe([Router::ASYNC, 2])
            ->and($router->route('/x/ws/'))->not->toBe([Router::ASYNC, 0])
            ->and($router->route('/a%20b/ws/'))->not->toBe([Router::ASYNC, 0])
            ->and($router->route('/%77s/'))->toBe([Router::ASYNC, 0]);       // the decoded path
    });
});
