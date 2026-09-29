#!/usr/bin/env python3
"""summarize.py RAWDIR MODE: one line per run and the tables (median of the rounds) for SUMMARY.md.

CPU shares come from the two /proc/stat snapshots in each raw file (8 s apart, inside the 10 s run):
lan: server = all 32 CPUs of black, client = this machine's busiest softirq CPU and its whole load;
lo:  server = CCD0 (0-7,16-23), client = CCD1 (8-15,24-31), both on black."""
import glob, os, re, statistics, sys
from collections import defaultdict

CCD0 = set(list(range(0, 8)) + list(range(16, 24)))
CCD1 = set(list(range(8, 16)) + list(range(24, 32)))


def cpu(snap_text):
    a, _, b = snap_text.partition('--\n')
    def parse(t):
        d = {}
        for line in t.splitlines():
            m = re.match(r'cpu(\d+) (.*)', line)
            if m:
                v = list(map(int, m.group(2).split()))
                busy = v[0] + v[1] + v[2] + v[5] + v[6] + v[7]
                d[int(m.group(1))] = (busy, busy + v[3] + v[4], v[6])
        return d
    x, y = parse(a), parse(b)
    out = {}
    for c in x:
        if c in y:
            dt = y[c][1] - x[c][1]
            out[c] = ((y[c][0] - x[c][0]) / dt if dt else 0, (y[c][2] - x[c][2]) / dt if dt else 0)
    return out


def share(u, cpus):
    cs = [c for c in u if c in cpus]
    return 100 * sum(u[c][0] for c in cs) / len(cs) if cs else 0


def ms(s):
    m = re.match(r'([\d.]+)(us|ms|s)', s)
    v = float(m.group(1))
    return {'us': v / 1000, 'ms': v, 's': v * 1000}[m.group(2)]


def parse(f, mode):
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
    m = re.search(r'## drops (\d+) (\d+)', t)
    r['drops'] = int(m.group(2)) - int(m.group(1)) if m else None
    ss = re.search(r'## server-stat\n(.*?)(?=## client-stat|\Z)', t, re.S)
    su = cpu(ss.group(1)) if ss else {}
    if mode == 'lo':
        r['srv_cpu'] = share(su, CCD0)
        r['cli_cpu'] = share(su, CCD1)
        r['cli_max'] = max((su[c][0] for c in su if c in CCD1), default=0) * 100
    else:
        r['srv_cpu'] = share(su, set(su))
        cs = re.search(r'## client-stat\n(.*)', t, re.S)
        cu = cpu(cs.group(1)) if cs else {}
        r['cli_cpu'] = share(cu, set(cu))
        top = max(cu, key=lambda c: cu[c][1]) if cu else None
        r['cli_sirq'] = (cu[top][1] * 100, cu[top][0] * 100, top) if top is not None else None
    return r


def main():
    raw, mode = sys.argv[1], sys.argv[2]
    runs = defaultdict(list)
    for f in sorted(glob.glob(f'{raw}/{mode}/r*-N*-*.txt')):
        m = re.match(r'r(\d+)-N(\d+)-(.+)-(hello|json|wait|page|wait1k|wait10k)\.txt$', os.path.basename(f))
        if not m:
            continue
        r = parse(f, mode)
        rd, n, srv, sc = int(m.group(1)), int(m.group(2)), m.group(3), m.group(4)
        runs[(sc, n, srv)].append(r)
        extra = (f"client CCD1 {r['cli_cpu']:.0f}% (max cpu {r['cli_max']:.0f}%)" if mode == 'lo'
                 else f"client softirq cpu{r['cli_sirq'][2]} {r['cli_sirq'][0]:.0f}% (busy {r['cli_sirq'][1]:.0f}%), drops {r['drops']}" if r.get('cli_sirq') else '')
        print(f"r{rd} {sc:7} N={n:<2} {srv:22} {r['rps']:>11,.0f} req/s p50 {r['p50']} p99 {r['p99']} ms  err {r['err']} non2xx {r['non2xx']}  server cpu {r['srv_cpu']:.0f}%  {extra}")
    print()
    order = ['swerve', 'swerve-ext', 'rr', 'franken', 'swoole', 'openswoole', 'react']
    base = lambda s: re.split(r'[+*]', s)[0]
    servers = sorted({k[2] for k in runs}, key=lambda s: (order.index(base(s)) if base(s) in order else 99, s))
    ns = sorted({k[1] for k in runs})
    for sc in ('hello', 'json', 'wait', 'page', 'wait1k', 'wait10k'):
        if not any(k[0] == sc for k in runs):
            continue
        print(f"### {sc}\n")
        ratio = len(ns) == 2
        print('| Server | ' + ' | '.join(f'N={n}: req/s / p99 / CPUs' for n in ns) + (f' | N={ns[1]} ÷ N={ns[0]} |' if ratio else ' |'))
        print('|---|' + '---:|' * (len(ns) + ratio))
        for s in servers:
            cells = []
            med = {}
            for n in ns:
                rs = runs.get((sc, n, s))
                if not rs:
                    cells.append('–')
                    continue
                rps = statistics.median(r['rps'] for r in rs)
                med[n] = rps
                p99 = statistics.median(r['p99'] for r in rs if r['p99'] is not None) if any(r['p99'] for r in rs) else 0
                flag = ''
                if any(r['err'] or r['non2xx'] for r in rs):
                    flag += ' E'
                if mode == 'lan' and any(r.get('cli_sirq') and r['cli_sirq'][0] > 85 for r in rs):
                    flag += ' C'
                if mode == 'lo' and any(r['cli_cpu'] > 85 for r in rs):
                    flag += ' C'
                cpus = statistics.median(r['srv_cpu'] for r in rs) * (16 if mode == 'lo' else 32) / 100
                cells.append(f"{rps:,.0f} / {p99:.2f} ms / {cpus:.1f}{flag}")
            if not med:
                continue
            if ratio:
                cells.append(f"{med[ns[1]] / med[ns[0]]:.2f}×" if med.get(ns[0]) and med.get(ns[1]) else '–')
            print(f'| {s} | ' + ' | '.join(cells) + ' |')
        print()


def waitmax(raw):
    """The /wait limit runs: the sum over the wrk processes, their worst p99, errors, server CPUs."""
    rows = defaultdict(list)
    for f in sorted(glob.glob(f'{raw}/waitmax/r*-N*-*-c*.txt')):
        m = re.match(r'r(\d+)-N(\d+)-(.+)-c(\d+)\.txt$', os.path.basename(f))
        t = open(f).read()
        rps = sum(float(x) for x in re.findall(r'Requests/sec:\s+([\d.]+)', t))
        p99 = max((ms(x) for x in re.findall(r'^\s+99%\s+(\S+)', t, re.M)), default=0)
        err = sum(sum(map(int, g)) for g in re.findall(r'Socket errors: connect (\d+), read (\d+), write (\d+), timeout (\d+)', t))
        tmo = sum(int(x) for x in re.findall(r'timeout (\d+)', t))
        ss = re.search(r'## server-stat\n(.*)', t, re.S)
        cpus = share(cpu(ss.group(1)), CCD0) * 16 / 100 if ss else 0
        rows[(int(m.group(2)), m.group(3), int(m.group(4)))].append((rps, p99, err, tmo, cpus, int(m.group(1))))
    print('| N | Server | Connections | req/s (per round) | p99 (worst wrk) | socket errors (timeouts) | server CPUs |')
    print('|---:|---|---:|---:|---:|---:|---:|')
    order = ['swerve', 'swerve-ext', 'swoole', 'openswoole', 'react', 'rr', 'franken']
    for (n, s, c) in sorted(rows, key=lambda k: (k[0], order.index(k[1]) if k[1] in order else 99, k[2])):
        v = rows[(n, s, c)]
        print(f"| {n} | {s} | {c:,} | {' · '.join(f'{x[0]:,.0f}' for x in v)} | {' · '.join(f'{x[1]:.1f} ms' for x in v)} | {' · '.join(f'{x[2]} ({x[3]})' for x in v)} | {' · '.join(f'{x[4]:.1f}' for x in v)} |")


def ws(raw, sub):
    """WebSocket fan-out: per run, the median over the 8 messages of their p50 and p99, the slowest
    message's last delivery, how many sockets got every message, memory per socket, client CCD1 CPU."""
    print('| Server | Workers | Sockets asked | Connected | Every message to every socket | p50 | p99 | last (slowest message) | KiB per socket | client CCD1 CPU |')
    print('|---|---:|---:|---:|---|---:|---:|---:|---:|---:|')
    order = ['swerve', 'swerve-ext', 'swoole', 'openswoole', 'react']
    files = sorted(glob.glob(f'{raw}/{sub}/r*-*-w*-*.txt'))
    keyed = []
    for f in files:
        m = re.match(r'r(\d+)-(.+)-w(\d+)-(\d+)\.txt$', os.path.basename(f))
        keyed.append(((int(m.group(4)), order.index(m.group(2)), int(m.group(1))), m, f))
    for _, m, f in sorted(keyed):
        t = open(f).read()
        conn = int(re.search(r'connected (\d+)', t).group(1))
        msgs = re.findall(r'^\d+\s+(\d+)\s+([\d.]+)ms\s+([\d.]+)ms\s+[\d.]+ms\s+([\d.]+)ms', t, re.M)
        allgot = all(int(x[0]) == conn for x in msgs) and len(msgs) == 8
        kib = re.search(r'per_socket_kib=(-?\d+)', t).group(1)
        if not msgs:
            print(f"| {m.group(2)} (r{m.group(1)}) | {m.group(3)} | {int(m.group(4)):,} | {conn:,} | NO: no message delivered | – | – | – | {kib} | – |")
            continue
        p50 = statistics.median(float(x[1]) for x in msgs)
        p99 = statistics.median(float(x[2]) for x in msgs)
        last = max(float(x[3]) for x in msgs)
        kib = re.search(r'per_socket_kib=(-?\d+)', t).group(1)
        ss = re.search(r'## server-stat\n(.*?)(?=\n## server=|\Z)', t, re.S)
        cli = f"{share(cpu(ss.group(1)), CCD1):.0f}%" if ss else '–'
        print(f"| {m.group(2)} (r{m.group(1)}) | {m.group(3)} | {int(m.group(4)):,} | {conn:,} | {'yes' if allgot else 'NO'} | {p50:.1f} ms | {p99:.1f} ms | {last:.1f} ms | {kib} | {cli} |")


if len(sys.argv) > 2 and sys.argv[2] == 'ws':
    ws(sys.argv[1], sys.argv[3] if len(sys.argv) > 3 else 'ws-lo')
elif len(sys.argv) > 2 and sys.argv[2] == 'waitmax':
    waitmax(sys.argv[1])
else:
    main()
