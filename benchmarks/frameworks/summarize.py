#!/usr/bin/env python3
"""summarize.py RAWDIR: one line per run, then per framework and route a table (median of the
rounds): req/s / p99 / CPUs busy on CCD0, for N=2 and N=8, and N=8 ÷ N=2.

CPU shares come from the two /proc/stat snapshots in each raw file (8 s apart, inside the 10 s
run): server = CCD0 (0-7,16-23), client = CCD1 (8-15,24-31)."""
import glob, os, re, statistics, sys
from collections import defaultdict

CCD0 = set(list(range(0, 8)) + list(range(16, 24)))
CCD1 = set(list(range(8, 16)) + list(range(24, 32)))
FWS = ['laravel', 'symfony', 'slim', 'spiral', 'yii', 'codeigniter']
ROUTES = ['json', 'session', 'wait', 'wait1k']
ORDER = ['swerve', 'swerve-ext', 'rr', 'franken', 'swoole', 'swoole-process', 'react']


def cpu(snap_text):
    a, _, b = snap_text.partition('--\n')
    def parse(t):
        d = {}
        for line in t.splitlines():
            m = re.match(r'cpu(\d+) (.*)', line)
            if m:
                v = list(map(int, m.group(2).split()))
                busy = v[0] + v[1] + v[2] + v[5] + v[6] + v[7]
                d[int(m.group(1))] = (busy, busy + v[3] + v[4])
        return d
    x, y = parse(a), parse(b)
    return {c: (y[c][0] - x[c][0]) / (y[c][1] - x[c][1]) for c in x if c in y and y[c][1] > x[c][1]}


def ms(s):
    m = re.match(r'([\d.]+)(us|ms|s|m)$', s)
    v = float(m.group(1))
    return {'us': v / 1000, 'ms': v, 's': v * 1000, 'm': v * 60000}[m.group(2)]


def parse(f):
    t = open(f).read()
    r = {}
    m = re.search(r'Requests/sec:\s+([\d.]+)', t)
    r['rps'] = float(m.group(1)) if m else 0.0
    for q in ('50', '99'):
        m = re.search(r'^\s+%s%%\s+(\S+)' % q, t, re.M)
        r['p' + q] = ms(m.group(1)) if m else None
    m = re.search(r'Socket errors: connect (\d+), read (\d+), write (\d+), timeout (\d+)', t)
    r['err'] = sum(map(int, m.groups())) if m else 0
    r['errtxt'] = m.group(0) if m else ''
    m = re.search(r'Non-2xx or 3xx responses: (\d+)', t)
    r['non2xx'] = int(m.group(1)) if m else 0
    ss = re.search(r'## server-stat\n(.*)', t, re.S)
    u = cpu(ss.group(1)) if ss else {}
    r['srv'] = sum(u[c] for c in u if c in CCD0)
    r['cli'] = 100 * sum(u[c] for c in u if c in CCD1) / 16
    return r


def fmt_ms(v):
    return f'{v / 1000:.2f} s' if v >= 1000 else f'{v:.2f} ms'


def main():
    raw = sys.argv[1]
    runs = defaultdict(list)
    pat = re.compile(r'r(\d+)-N(\d+)-(%s)-(.+)-(%s)\.txt$' % ('|'.join(FWS), '|'.join(ROUTES)))
    for f in sorted(glob.glob(f'{raw}/r*-N*.txt')):
        m = pat.match(os.path.basename(f))
        if not m:
            continue
        r = parse(f)
        rd, n, fw, srv, route = int(m.group(1)), int(m.group(2)), m.group(3), m.group(4), m.group(5)
        runs[(fw, route, n, srv)].append(r)
        print(f"r{rd} N={n} {fw:11} {srv:10} {route:7} {r['rps']:>10,.0f} req/s p50 {r['p50']} p99 {r['p99']} ms"
              f"  err {r['err']} non2xx {r['non2xx']}  server CPUs {r['srv']:.1f}  client CCD1 {r['cli']:.0f}%")
    print()
    for fw in FWS:
        if not any(k[0] == fw for k in runs):
            continue
        print(f'### {fw}\n')
        servers = sorted({k[3] for k in runs if k[0] == fw}, key=lambda s: (ORDER.index(s) if s in ORDER else 99, s))
        for route in ROUTES:
            if not any(k[0] == fw and k[1] == route for k in runs):
                continue
            conns = '1,000 connections' if route == 'wait1k' else '16 connections per worker'
            print(f'#### {route} ({conns})\n')
            print('| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |')
            print('|---|---:|---:|---:|')
            for s in servers:
                cells, med = [], {}
                for n in (2, 8):
                    rs = runs.get((fw, route, n, s))
                    if not rs:
                        cells.append('–')
                        continue
                    med[n] = statistics.median(r['rps'] for r in rs)
                    p99s = [r['p99'] for r in rs if r['p99'] is not None]
                    p99 = statistics.median(p99s) if p99s else 0
                    flag = ' E' if any(r['err'] or r['non2xx'] for r in rs) else ''
                    cpus = statistics.median(r['srv'] for r in rs)
                    cells.append(f"{med[n]:,.0f} / {fmt_ms(p99)} / {cpus:.1f}{flag}")
                ratio = f'{med[8] / med[2]:.2f}×' if 2 in med and 8 in med and med[2] else '–'
                print(f'| {s} | ' + ' | '.join(cells) + f' | {ratio} |')
            print()
    # rounds agreement
    spread = [(abs(rs[0]['rps'] - rs[1]['rps']) / max(r['rps'] for r in rs), k) for k, rs in runs.items()
              if len(rs) == 2 and max(r['rps'] for r in rs) > 0]
    spread.sort(reverse=True)
    print('Largest round-to-round differences:')
    for d, k in spread[:12]:
        print(f'  {d * 100:.1f}%  {k}')


main()
