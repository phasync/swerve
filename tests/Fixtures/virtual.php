<?php

/*
 * An application written for PHP-FPM, served with Swerve::virtualize() (needs phasync-ext): it
 * echoes and calls header(), and returns nothing worth sending.
 */

use phasync\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Swerve;

Swerve::virtualize();

return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ('/hello' === $path) {
            return new Response(200, ['Content-Type' => 'text/plain'], 'Hello'); // a PSR response, nothing echoed
        }
        match ($path) {
            // A session counter, a header, a cookie, the request body, a pause of ?ms= between two outputs; ?exit=1 exits after the first
            '/virtual' => (function () {
                \session_save_path(\sys_get_temp_dir());
                \session_start();
                $_SESSION['n'] = ($_SESSION['n'] ?? 0) + 1;
                \http_response_code(201);
                \header('X-Virtual: yes');
                \setcookie('flavour', 'oat');
                echo 'n=', $_SESSION['n'], ' body=', \file_get_contents('php://input'), ' sid=', \session_id(), "\n";
                \flush();
                if (!empty($_GET['exit'])) {
                    exit(1);
                }
                \phasync::sleep((int) ($_GET['ms'] ?? 0) / 1000);
                echo "last\n";
            })(),
            // The request's own $_SESSION: ?v= written, read back after waiting ?ms=; without ?v=, what the session holds
            '/virtual-session' => (function () {
                \session_save_path(\sys_get_temp_dir());
                \session_start();
                if (isset($_GET['v'])) {
                    $_SESSION['v'] = $_GET['v'];
                    \phasync::sleep((int) ($_GET['ms'] ?? 0) / 1000);
                    $_SESSION['after'] = $_GET['v'];
                }
                echo \session_id(), ' ', ($_SESSION['v'] ?? '-'), ' ', ($_SESSION['after'] ?? '-');
            })(),
            // The request's own superglobals, read before and after waiting ?ms=
            '/virtual-globals' => (function () {
                $read   = static fn () => ($_GET['q'] ?? '-') . '|' . ($_COOKIE['c'] ?? '-') . '|' . ($_SERVER['HTTP_X_T'] ?? '-') . '|' . ($_POST['p'] ?? '-');
                $before = $read();
                \phasync::sleep((int) ($_GET['ms'] ?? 0) / 1000);
                echo $before, ' ', $read();
            })(),
            // A form: what PHP made of it, and what the PSR request has
            '/virtual-form' => (function () use ($request) {
                $psr = [];
                foreach ($request->getUploadedFiles() as $field => $list) {
                    foreach ((array) $list as $f) {
                        $psr[$field][] = $f->getClientFilename() . ':' . $f->getSize() . ':' . $f->getStream()->getContents();
                    }
                }
                echo \json_encode([
                    'post'   => $_POST,
                    'files'  => \array_map(static fn ($f) => $f['name'], $_FILES),
                    'parsed' => $request->getParsedBody(),
                    'psr'    => $psr,
                    'input'  => \file_get_contents('php://input'),
                ]);
            })(),
            // Output arrives as it is made: ?n= pieces, ?ms= apart
            '/virtual-stream' => (function () {
                for ($i = 1; $i <= (int) $_GET['n']; ++$i) {
                    echo "piece$i\n";
                    \flush();
                    \phasync::sleep((int) $_GET['ms'] / 1000);
                }
            })(),
            // The application's own Content-Length
            '/virtual-length' => (function () {
                \header('Content-Length: 5');
                echo 'hello';
                exit; // PHP discards the output of a HEAD request, so the handler's own return would win
            })(),
            // A redirect: headers and no output
            '/virtual-redirect' => (function () {
                \header('Location: /hello', true, 302);
                exit;
            })(),
            default => (function () {
                \http_response_code(404);
                echo 'Not found';
            })(),
        };

        return new Response(500, [], 'the handler returned: nothing echoed was lost');
    }
};
