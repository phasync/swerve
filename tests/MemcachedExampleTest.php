<?php

/*
 * examples/memcached: a memcached text-protocol server in every worker, storing in the shared
 * cache. Raw protocol over sockets, with two workers: the replies, the cas/incr/append
 * guarantees across workers, and the drain.
 */

function mc_start(): array
{
    $port = (int) substr(strrchr(free_address(), ':'), 1);
    [$process, $addr, $log] = swerve_start([], 2, env: ['MEMCACHED_PORT' => (string) $port], fixture: __DIR__ . '/../examples/memcached/swerve.php');

    return [$process, $port, $log];
}

function mc_connect(int $port)
{
    $conn = stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 5);
    stream_set_timeout($conn, 5);

    return $conn;
}

function mc_read($conn, int $length): string
{
    $out = '';
    while (strlen($out) < $length) {
        $chunk = fread($conn, $length - strlen($out));
        if (false === $chunk || '' === $chunk) {
            break;
        }
        $out .= $chunk;
    }

    return $out;
}

/** Send `$send` and expect exactly `$expected` back. */
function mc($conn, string $send, string $expected): void
{
    fwrite($conn, $send);
    expect(mc_read($conn, strlen($expected)))->toBe($expected);
}

/** The pid of the worker behind a connection. */
function mc_pid($conn): int
{
    fwrite($conn, "stats\r\n");
    $stats = read_until($conn, "END\r\n");
    preg_match('/STAT pid (\d+)/', $stats, $m);

    return (int) $m[1];
}

/** Connections until both workers have some: [pid => [connections]]. */
function mc_spread(int $port, int $perWorker = 3): array
{
    $byPid = [];
    for ($i = 0; $i < 100 && (count($byPid) < 2 || min(array_map('count', $byPid)) < $perWorker); ++$i) {
        $conn                   = mc_connect($port);
        $byPid[mc_pid($conn)][] = $conn;
    }
    expect(count($byPid))->toBe(2);

    return $byPid;
}

function mc_stop($process, string $log): void
{
    native_stop($process);
    expect(log_count($log, '/(error|critical)/'))->toBe(0, file_get_contents($log));
}

test('memcached: values, flags, bytes, multi-get, add, replace, append, prepend, delete, touch', function () {
    [$process, $port, $log] = mc_start();
    try {
        $c = mc_connect($port);
        mc($c, "get a\r\n", "END\r\n");
        // Flags and raw bytes, a line ending and a NUL inside, come back as sent
        $raw = "line\r\n\0end\r\nEND\r\n";
        mc($c, 'set a 4294967295 0 ' . strlen($raw) . "\r\n$raw\r\n", "STORED\r\n");
        mc($c, "get a\r\n", 'VALUE a 4294967295 ' . strlen($raw) . "\r\n$raw\r\nEND\r\n");
        // Several keys, in the order asked, misses left out, a key twice
        mc($c, "set b 7 0 1\r\nB\r\n", "STORED\r\n");
        mc($c, "get b nothing a b\r\n", "VALUE b 7 1\r\nB\r\nVALUE a 4294967295 " . strlen($raw) . "\r\n$raw\r\nVALUE b 7 1\r\nB\r\nEND\r\n");
        // add and replace
        mc($c, "add b 0 0 1\r\nX\r\n", "NOT_STORED\r\n");
        mc($c, "add c 0 0 1\r\nC\r\n", "STORED\r\n");
        mc($c, "replace nope 0 0 1\r\nX\r\n", "NOT_STORED\r\n");
        mc($c, "replace c 3 0 2\r\nCC\r\n", "STORED\r\n");
        mc($c, "get c\r\n", "VALUE c 3 2\r\nCC\r\nEND\r\n");
        // append and prepend keep the flags
        mc($c, "append c 9 0 1\r\n>\r\n", "STORED\r\n");
        mc($c, "prepend c 9 0 1\r\n<\r\n", "STORED\r\n");
        mc($c, "get c\r\n", "VALUE c 3 4\r\n<CC>\r\nEND\r\n");
        mc($c, "append nope 0 0 1\r\nX\r\n", "NOT_STORED\r\n");
        // delete and touch
        mc($c, "delete c\r\n", "DELETED\r\n");
        mc($c, "delete c\r\n", "NOT_FOUND\r\n");
        mc($c, "delete b 0\r\n", "DELETED\r\n");
        mc($c, "touch a 100\r\n", "TOUCHED\r\n");
        mc($c, "touch c 100\r\n", "NOT_FOUND\r\n");
        // Keys with the characters the cache refuses, and percent signs, are different keys
        foreach (['user:1', 'a/b', 'a@b', 'a{b}', 'a(b)', 'a\\b', 'a%3Ab', 'user%3A1', '%'] as $i => $key) {
            mc($c, "set $key 0 0 1\r\n$i\r\n", "STORED\r\n");
        }
        foreach (['user:1', 'a/b', 'a@b', 'a{b}', 'a(b)', 'a\\b', 'a%3Ab', 'user%3A1', '%'] as $i => $key) {
            mc($c, "get $key\r\n", "VALUE $key 0 1\r\n$i\r\nEND\r\n");
        }
        mc($c, "version\r\n", "VERSION 1.6.0\r\n");
        mc($c, "flush_all\r\n", "OK\r\n");
        mc($c, "get a user:1\r\n", "END\r\n");
        mc($c, "flush_all 0\r\n", "OK\r\n");
        mc($c, "quit\r\n", '');
        expect(fgets($c))->toBeFalse();   // closed
    } finally {
        mc_stop($process, $log);
    }
});

test('memcached: exptime is never, already past, seconds, or an absolute time after 30 days', function () {
    [$process, $port, $log] = mc_start();
    try {
        $c = mc_connect($port);
        mc($c, "set never 0 0 1\r\nx\r\n", "STORED\r\n");
        mc($c, "set past 0 -1 1\r\nx\r\n", "STORED\r\n");
        mc($c, "get past\r\n", "END\r\n");
        mc($c, "set short 0 2 1\r\nx\r\n", "STORED\r\n");
        mc($c, 'set absolute 0 ' . (time() + 2) . " 1\r\nx\r\n", "STORED\r\n");
        mc($c, 'set longgone 0 ' . (time() - 10) . " 1\r\nx\r\n", "STORED\r\n");
        mc($c, "set touched 0 0 1\r\nx\r\n", "STORED\r\n");
        mc($c, "touch touched 2\r\n", "TOUCHED\r\n");
        mc($c, "set inc 0 2 1\r\n1\r\n", "STORED\r\n");
        mc($c, "incr inc 1\r\n", "2\r\n");          // the expiry stays
        mc($c, "append short 0 0 1\r\ny\r\n", "STORED\r\n");   // the expiry stays
        mc($c, "get longgone\r\n", "END\r\n");
        mc($c, "get short absolute touched inc\r\n", "VALUE short 0 2\r\nxy\r\nVALUE absolute 0 1\r\nx\r\nVALUE touched 0 1\r\nx\r\nVALUE inc 0 1\r\n2\r\nEND\r\n");
        usleep(3_200_000);
        mc($c, "get never short absolute touched inc\r\n", "VALUE never 0 1\r\nx\r\nEND\r\n");
        mc($c, "touch never -1\r\n", "TOUCHED\r\n");
        mc($c, "get never\r\n", "END\r\n");
    } finally {
        mc_stop($process, $log);
    }
});

test('memcached: cas, incr, decr, noreply', function () {
    [$process, $port, $log] = mc_start();
    try {
        $c = mc_connect($port);
        mc($c, "cas k 0 0 1 1\r\nx\r\n", "NOT_FOUND\r\n");
        mc($c, "set k 5 0 1\r\nx\r\n", "STORED\r\n");
        fwrite($c, "gets k\r\n");
        preg_match('/^VALUE k 5 1 (\d+)\r\nx\r\nEND\r\n$/', read_until($c, "END\r\n"), $m);
        $first = $m[1];
        mc($c, "cas k 6 0 1 $first\r\ny\r\n", "STORED\r\n");
        mc($c, "cas k 6 0 1 $first\r\nz\r\n", "EXISTS\r\n");
        fwrite($c, "gets k\r\n");
        preg_match('/^VALUE k 6 1 (\d+)\r\ny\r\nEND\r\n$/', read_until($c, "END\r\n"), $m);
        expect($m[1])->not->toBe($first);
        // Every write changes the id, a write of the same value too
        mc($c, "set k 6 0 1\r\ny\r\n", "STORED\r\n");
        fwrite($c, "gets k\r\n");
        preg_match('/^VALUE k 6 1 (\d+)\r\n/', read_until($c, "END\r\n"), $again);
        expect($again[1])->not->toBe($m[1]);

        mc($c, "incr nope 1\r\n", "NOT_FOUND\r\n");
        mc($c, "set n 2 0 2\r\n10\r\n", "STORED\r\n");
        mc($c, "incr n 5\r\n", "15\r\n");
        mc($c, "decr n 6\r\n", "9\r\n");
        mc($c, "decr n 100\r\n", "0\r\n");
        mc($c, "get n\r\n", "VALUE n 2 1\r\n0\r\nEND\r\n");
        mc($c, "incr n 9223372036854775807\r\n", "9223372036854775807\r\n");
        mc($c, "incr n 1\r\n", "CLIENT_ERROR increment or decrement overflow\r\n");
        mc($c, "incr k 1\r\n", "CLIENT_ERROR cannot increment or decrement non-numeric value\r\n");
        mc($c, "incr n abc\r\n", "CLIENT_ERROR invalid numeric delta argument\r\n");

        // noreply: nothing comes back, errors included; the next reply is the version's
        mc($c, "set q 0 0 1 noreply\r\nx\r\nadd q 0 0 1 noreply\r\ny\r\nincr q 1 noreply\r\ndelete nope noreply\r\ntouch q 5 noreply\r\n"
            . "append q 0 0 1 noreply\r\nz\r\ncas q 0 0 1 1 noreply\r\nw\r\nset " . str_repeat('k', 251) . " 0 0 1 noreply\r\nversion\r\n", "VERSION 1.6.0\r\n");
        mc($c, "get q\r\n", "VALUE q 0 2\r\nxz\r\nEND\r\n");
        mc($c, "flush_all noreply\r\nget q\r\n", "END\r\n");
    } finally {
        mc_stop($process, $log);
    }
});

test('memcached: ERROR, CLIENT_ERROR and SERVER_ERROR, and the connection goes on', function () {
    [$process, $port, $log] = mc_start();
    try {
        $c = mc_connect($port);
        mc($c, "\r\n", "ERROR\r\n");
        mc($c, "nonsense\r\n", "ERROR\r\n");
        mc($c, "GET a\r\n", "ERROR\r\n");
        mc($c, "get\r\n", "ERROR\r\n");
        mc($c, "set a 0 0\r\n", "ERROR\r\n");
        mc($c, "incr a\r\n", "ERROR\r\n");
        mc($c, 'get ' . str_repeat('k', 251) . "\r\n", "CLIENT_ERROR bad command line format\r\n");
        mc($c, "get a\tb\r\n", "CLIENT_ERROR bad command line format\r\n");
        mc($c, 'set ' . str_repeat('k', 250) . " 0 0 1\r\nx\r\n", "STORED\r\n");
        mc($c, 'get ' . str_repeat('k', 250) . "\r\n", 'VALUE ' . str_repeat('k', 250) . " 0 1\r\nx\r\nEND\r\n");
        mc($c, "set a x 0 1\r\n", "CLIENT_ERROR bad command line format\r\n");
        mc($c, "x\r\n", "ERROR\r\n");   // the data block of that set, as memcached sees it too
        mc($c, "set a 4294967296 0 1\r\n", "CLIENT_ERROR bad command line format\r\n");
        mc($c, "x\r\n", "ERROR\r\n");
        mc($c, "set a 0 0 -1\r\n", "CLIENT_ERROR bad command line format\r\n");
        mc($c, "set a 0 0 3\r\nabcde\r\n", "CLIENT_ERROR bad data chunk\r\n");
        mc($c, '', "ERROR\r\n");   // what is left of that data block is an empty command
        mc($c, "flush_all 10\r\n", "CLIENT_ERROR a delayed flush_all is not supported\r\n");
        // A value of 1 MB, and one byte more: refused, its data thrown away, the old value gone
        $mb = str_repeat('m', 1048576);
        mc($c, "set big 1 0 1048576\r\n$mb\r\n", "STORED\r\n");
        mc($c, "get big\r\n", "VALUE big 1 1048576\r\n$mb\r\nEND\r\n");
        mc($c, "set big 1 0 1048577\r\n$mb" . "m\r\n", "SERVER_ERROR object too large for cache\r\n");
        mc($c, "get big\r\n", "END\r\n");
        mc($c, "set big 1 0 1048576\r\n$mb\r\n", "STORED\r\n");
        mc($c, "append big 0 0 1\r\nx\r\n", "SERVER_ERROR object too large for cache\r\n");
        mc($c, "get big\r\n", "VALUE big 1 1048576\r\n$mb\r\nEND\r\n");
        // A value arriving in small pieces
        fwrite($c, "set slow 0 0 10\r\n01234");
        usleep(100_000);
        fwrite($c, "56789\r");
        usleep(100_000);
        mc($c, "\nget slow\r\n", "STORED\r\nVALUE slow 0 10\r\n0123456789\r\nEND\r\n");
        // Commands pipelined in one write
        mc($c, "set p 0 0 1\r\n1\r\nincr p 1\r\nget p\r\ndelete p\r\n", "STORED\r\n2\r\nVALUE p 0 1\r\n2\r\nEND\r\nDELETED\r\n");
        // A line that never ends
        $d = mc_connect($port);
        fwrite($d, str_repeat('a', 70000));
        expect(mc_read($d, 28))->toBe("CLIENT_ERROR line too long\r\n");
        expect(fgets($d))->toBeFalse();
        // The counters, per worker
        fwrite($c, "stats\r\n");
        $stats = read_until($c, "END\r\n");
        expect($stats)->toMatch('/STAT pid \d+\r\n/')->toMatch('/STAT curr_connections [12]\r\n/')->toContain("STAT get_hits ")->toContain("STAT total_connections ");
        mc($c, "stats settings\r\n", "END\r\n");
    } finally {
        mc_stop($process, $log);
    }
});

test('memcached: what one worker stores, every worker serves at once', function () {
    [$process, $port, $log] = mc_start();
    try {
        $byPid = mc_spread($port);
        [$a, $b] = array_map(static fn (array $conns) => $conns[0], array_values($byPid));
        // A value stored through one worker is read through the other, straight away, every time
        for ($i = 0; $i < 150; ++$i) {
            mc($a, "set shared 0 0 " . strlen("v$i") . "\r\nv$i\r\n", "STORED\r\n");
            mc($b, "get shared\r\n", 'VALUE shared 0 ' . strlen("v$i") . "\r\nv$i\r\nEND\r\n");
            mc($b, "set shared 0 0 " . strlen("w$i") . "\r\nw$i\r\n", "STORED\r\n");
            mc($a, "get shared\r\n", 'VALUE shared 0 ' . strlen("w$i") . "\r\nw$i\r\nEND\r\n");
        }
        mc($a, "delete shared\r\n", "DELETED\r\n");
        mc($b, "get shared\r\n", "END\r\n");
        mc($a, "set x 0 0 1\r\n1\r\n", "STORED\r\n");
        mc($b, "flush_all\r\n", "OK\r\n");
        mc($a, "get x\r\n", "END\r\n");
        // Connections stay with their worker, and the counters are theirs
        foreach ($byPid as $pid => $conns) {
            foreach ($conns as $conn) {
                expect(mc_pid($conn))->toBe($pid);
            }
        }
    } finally {
        mc_stop($process, $log);
    }
});

test('memcached: incr, append and cas are atomic across the workers', function () {
    [$process, $port, $log] = mc_start();
    try {
        $conns = array_merge(...array_values(mc_spread($port, 4)));
        $n     = 60;
        mc($conns[0], "set counter 0 0 1\r\n0\r\n", "STORED\r\n");
        mc($conns[0], "set text 0 0 0\r\n\r\n", "STORED\r\n");
        // Every connection fires its commands at once, without waiting for answers
        foreach ($conns as $i => $conn) {
            $letter = chr(97 + $i);
            fwrite($conn, str_repeat("incr counter 1\r\nappend text 0 0 1\r\n$letter\r\n", $n));
        }
        $total = $n * count($conns);
        foreach ($conns as $conn) {
            $replies = '';
            while (substr_count($replies, "\r\n") < 2 * $n) {
                $replies .= mc_read($conn, 1);
                if (strlen($replies) > 100_000) {
                    break;
                }
            }
            expect(substr_count($replies, "STORED\r\n"))->toBe($n);
        }
        mc($conns[0], "get counter\r\n", 'VALUE counter 0 ' . strlen((string) $total) . "\r\n$total\r\nEND\r\n");
        fwrite($conns[0], "get text\r\n");
        $text = read_until($conns[0], "END\r\n");
        expect($text)->toStartWith("VALUE text 0 $total\r\n");
        $letters = substr($text, strlen("VALUE text 0 $total\r\n"), $total);
        foreach ($conns as $i => $conn) {
            expect(substr_count($letters, chr(97 + $i)))->toBe($n);
        }

        // Everyone has read the same version of a value and tries to replace it: one wins
        for ($round = 0; $round < 10; ++$round) {
            mc($conns[0], "set race 0 0 1\r\n0\r\n", "STORED\r\n");
            $unique = null;
            fwrite($conns[0], "gets race\r\n");
            preg_match('/^VALUE race 0 1 (\d+)\r\n/', read_until($conns[0], "END\r\n"), $m);
            foreach ($conns as $i => $conn) {
                fwrite($conn, "cas race 0 0 1 {$m[1]}\r\n" . ($i % 10) . "\r\n");
            }
            $outcomes = [];
            foreach ($conns as $conn) {
                $outcomes[] = trim(fgets($conn));
            }
            expect(array_count_values($outcomes))->toEqual(['STORED' => 1, 'EXISTS' => count($conns) - 1]);
        }
    } finally {
        mc_stop($process, $log);
    }
});

test('memcached: a drain closes the connections and the port, and logs no error', function () {
    [$process, $port, $log] = mc_start();
    try {
        $idle = mc_connect($port);
        mc($idle, "set a 0 0 1\r\nx\r\n", "STORED\r\n");
        swerve_signal($process, SIGINT);
        expect(fgets($idle))->toBeFalse();
        swerve_wait($process, 8);
        $stopped = true;
        set_error_handler(static fn (): bool => true);
        try {
            expect(stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 1))->toBeFalse();
        } finally {
            restore_error_handler();
        }
    } finally {
        if (!isset($stopped)) {
            native_stop($process);
        }
    }
    expect(log_count($log, '/(error|critical)/'))->toBe(0, file_get_contents($log));
});

test('memcached: a PHP memcached client', function () {
    if (!class_exists('Memcached')) {
        $this->markTestSkipped('ext-memcached is not installed');
    }
    [$process, $port, $log] = mc_start();
    try {
        $m = new Memcached();
        $m->addServer('127.0.0.1', $port);
        expect($m->set('user:1', ['name' => 'ann'], 60))->toBeTrue();
        expect($m->get('user:1'))->toBe(['name' => 'ann']);
        expect($m->getMulti(['user:1', 'nope']))->toBe(['user:1' => ['name' => 'ann']]);
        expect($m->add('user:1', 'x'))->toBeFalse();
        $m->set('n', 1);
        expect($m->increment('n', 4))->toBe(5);
        $m->get('user:1', null, $token);
        expect($m->cas($token, 'user:1', 'new'))->toBeTrue();
        expect($m->cas($token, 'user:1', 'newer'))->toBeFalse();
        expect($m->delete('user:1'))->toBeTrue();
    } finally {
        mc_stop($process, $log);
    }
});
