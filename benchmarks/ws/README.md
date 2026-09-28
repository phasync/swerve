# WebSocket fan-out: how many sockets, how fast a message reaches them all

`swerve.php` is the whole server: every connection to `/news` forwards what is published to
`news`, and `POST /publish` publishes its body.

```php
'/news' => WebSocket::from($request, static function (WebSocket $ws) {
    foreach (Swerve::subscribe('news', maxLag: 60) as $message) {
        $ws->send($message);
    }
}),
```

`client/` is a dependency-free Go client (`go build -o wsbench .`): it opens the connections,
publishes timestamped messages, and measures when each one arrives on every socket (one clock:
the client's). `run.sh WORKERS CONNS` runs swerve on the server (black) over ssh and the client
here, and reports the server's memory per socket.

## Results (2026-09-28)

Server: AMD Ryzen 9 9950X3D (16 cores, 32 threads, one NUMA node), PHP 8.5.11, swerve
0.1.0-alpha19, phasync 2.0.0-alpha14, phasync-ext 0.5.0-alpha10. Client: a second machine, 1 Gbit/s
LAN. Two listen ports (one client address has about 55,000 ports per server port).

| Sockets | Workers | Delivered | Median | Last socket | Memory per socket |
|---:|---:|---|---:|---:|---:|
| 10,000 | 16 | every message to every socket | 30–46 ms | 52–75 ms | 44 KiB |
| 100,000 | 32 | every message to every socket | 205–290 ms | 385–510 ms (one 1.19 s) | 43 KiB |

Eight messages a second apart; connections opened at 5,000 a second, none failed or dropped.
Raw output in `results/`. A message to 100,000 sockets is about 7 MB on the wire, 60 ms at
1 Gbit/s; the rest is swerve's cost per socket (about 90 µs per message and socket, divided over
the workers).
