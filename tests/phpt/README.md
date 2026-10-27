# phpt parity runner

`run.php` serves php-src's request-level `.phpt` tests (`--GET--`, `--POST--`, `--POST_RAW--`,
`--COOKIE--`, `--CGI--`, `--HEADERS--`, `--EXPECTHEADERS--`) through Swerve 0.2 — the FPM shape,
one `Swerve\Swerve::accept()` call per lifecycle — and reports only where Swerve differs from the
baseline.

```
PHASYNC_EXT_SO=/path/to/phasync.so php run.php <php-src-path> [dir ...]
```

`dir` is one or more directories relative to `<php-src-path>` (default: `tests/basic`,
`ext/standard`, `ext/session`, `ext/filter`, `ext/zlib`, `ext/mbstring`). `ext/phar`'s
front-controller tests are always excluded.

Baseline (silently): a live `php-cgi` (`php-cgiN.N` or `php-cgi` on `PATH`), if installed — swerve's
response is then compared to it byte-for-byte. Without one (the common case; this never installs
one), the phpt's own `--EXPECT--`/`--EXPECTF--`/`--EXPECTREGEX--` and `--EXPECTHEADERS--` sections
are the baseline, matched with the same wildcards and the same case-sensitive header-name lookup
as php-src's `run-tests.php` (`expectf_to_regex()` is ported verbatim). The php-cgi path follows
the same CGI env-var mapping as `run-tests.php` but is unexercised on a system without a php-cgi
binary — verify it before relying on it.

Tests are grouped by their effective `--INI--`/`--ENV--` settings (after folding in the baseline
ini run-tests.php always forces regardless of php.ini — see `BASE_INI`, chiefly
`display_errors=1`, `html_errors=0`, `date.timezone=UTC`); one swerve server per group serves
every test in it, by path, so a single server usually covers a whole directory. `--SKIPIF--` runs
under the plain CLI (not through swerve); extensions named in `--EXTENSIONS--` must already be
loaded in that CLI.

Out of scope, skipped silently: `--PUT--`, `--GZIP_POST--`, `--DEFLATE_POST--`, `--STDIN--`,
`--CAPTURE_STDIO--`, `--PHPDBG--`, `--ARGS--`, `--FILE_EXTERNAL--`, `--REDIRECTTEST--`,
`--FILEEOF--`, `--XLEAK--`, `--FLAKY--`, `--WHITESPACE_SENSITIVE--`, and `--XFAIL--` (upstream
already does not guarantee that test's output). None of these occur in the default directories'
request-level tests as of this writing.

Prints nothing for a test that matches. A differing test gets one `DIFFER <path>` block with a
compact line-based diff (and, for `--EXPECTHEADERS--`, the wanted vs. actual lines for just the
header names it names — a `Status` line is matched against the real HTTP status line and reason,
since an HTTP SAPI like Swerve has no CGI `Status:` header). Exits non-zero if anything differed.
A malformed phpt, or one whose swerve group failed to start, is reported as `BORK` on stderr
instead of counted as a difference.
