#!/usr/bin/env python3
"""Tables from raw/<app>-<route>-N<n>-<server>.txt: req/s with p99, per application, and the
checks (errors, non-2xx, qdisc drops, softirq, bytes on the wire). Prints Markdown."""
import glob, os, re, sys

here = os.path.dirname(os.path.abspath(__file__))
APPS = ['plain', 'laravel', 'symfony', 'yii', 'cakephp', 'spiral', 'codeigniter', 'laminas']
SERVERS = ['fpm', 'swerve', 'swerve-ext']
ROUTES = ['json', 'session', 'session-counter', 'wait']
LINK = 1000 / 8 * 1e6  # 1 Gbit/s in bytes/s
# Runs that measured something other than the server. The CakePHP runs that measured swap
# (phasync/phasync#52) were rerun with phasync 2.0.0-alpha14, which fixed it.
INVALID = set()

def size(s):
    m = re.match(r'([\d.]+)([KMG]?)B', s)
    return float(m[1]) * {'': 1, 'K': 1024, 'M': 1024**2, 'G': 1024**3}[m[2]]

runs = {}
for f in glob.glob(f'{here}/raw/*-N*-*.txt'):
    m = re.match(r'(\w+)-([\w-]+?)-N(\d+)-(fpm|swerve-ext|swerve)\.txt$', os.path.basename(f))
    if not m:
        continue
    t = open(f).read()
    g = lambda p: (re.search(p, t, re.M) or [None, None])[1]
    runs[(m[1], m[2], int(m[3]), m[4])] = {
        'rps': float(g(r'Requests/sec:\s+([\d.]+)') or 0), 'p50': g(r'^\s+50%\s+(\S+)'), 'p99': g(r'^\s+99%\s+(\S+)'),
        'errors': g(r'(Socket errors: .*)'), 'non2xx': int(g(r'Non-2xx or 3xx responses: (\d+)') or 0),
        'transfer': size(g(r'Transfer/sec:\s+(\S+)') or '0B'), 'drops': int(g(r'qdisc_drops: (-?\d+)') or 0),
        'softirq': [int(x) for x in re.findall(r'max_softirq=(\d+)%', t)],
        'client_softirq': int(g(r'^client: .*max_softirq=(\d+)%') or 0),
        'client_core': int(g(r'^client: .*max_cpu_busy=(\d+)%') or 0),
        'busy': g(r'^server: busy=(\d+)%'), 'client_busy': g(r'^client: busy=(\d+)%'),
    }

def client_bound(r):  # the client's one NIC queue core (IRQ + softirq) near saturation
    return r['client_softirq'] >= 50 and r['client_core'] >= 85

def cell(r, key):
    if not r:
        return '–'
    if (key[0], key[2], key[3]) in INVALID:
        return f"~~{r['rps']:,.0f}~~ §"
    flags = ('*' if r['transfer'] > 0.85 * LINK else '') + ('‡' if client_bound(r) else '') + ('†' if r['errors'] or r['non2xx'] else '')
    return f"{r['rps']:,.0f} ({r['p99']}){flags}"

out = []
for app in APPS:
    routes = [r for r in ROUTES if any(k[0] == app and k[1] == r for k in runs)]
    if not routes:
        continue
    ns = sorted({k[2] for k in runs if k[0] == app})
    out += [f'### {app}', '', '| route | N | FPM | swerve | swerve + ext |', '|---|---:|---:|---:|---:|']
    for route in routes:
        for n in ns:
            out.append(f'| {route} | {n} | ' + ' | '.join(cell(runs.get((app, route, n, s)), (app, route, n, s)) for s in SERVERS) + ' |')
    ratio = lambda route, s, n: '§' if (app, n, s) in INVALID else (lambda a, b: f"{a['rps'] / b['rps']:.1f}×" if a and b and b['rps'] else '–')(runs.get((app, route, n, s)), runs.get((app, route, n, 'fpm')))
    out += ['', 'Scaling, swerve/FPM at N=' + '/'.join(map(str, ns)) + ': ' + '; '.join(
        f"{route} " + ' / '.join(ratio(route, 'swerve', n) for n in ns)
        + (f" (with ext " + ' / '.join(ratio(route, 'swerve-ext', n) for n in ns) + ')' if route == 'wait' else '')
        for route in routes), '']

bad = [(k, r) for k, r in sorted(runs.items()) if r['errors'] or r['non2xx']]
out += ['### Checks', '',
        f"- Runs: {len(runs)}; with socket errors or non-2xx: {len(bad)}" + ''.join(f"\n  - {'-'.join(map(str, k))}: {r['errors'] or ''} {r['non2xx'] or ''} non-2xx" for k, r in bad),
        f"- qdisc drops on black's eno1: {sum(r['drops'] for r in runs.values())} in all runs",
        f"- Busiest softirq core: {max(max(r['softirq'] or [0]) for r in runs.values())}% (the highest of either machine, any run)",
        f"- Highest client (home) CPU: {max(int(r['client_busy'] or 0) for r in runs.values())}% of 56 threads",
        f"- Client NIC core near saturation (softirq >= 50%, core >= 85%): " + (', '.join('-'.join(map(str, k)) for k, r in sorted(runs.items()) if client_bound(r)) or 'none'),
        f"- Near the 1 Gbit/s link (> 85%): " + (', '.join('-'.join(map(str, k)) for k, r in sorted(runs.items()) if r['transfer'] > 0.85 * LINK) or 'none')]
print('\n'.join(out))
