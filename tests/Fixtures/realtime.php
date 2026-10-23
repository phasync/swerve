<?php

/*
 * The application of the WebSocket and Server-Sent Events tests: swerve's WebSocket and
 * ServerSentEvents served as an application would serve them.
 */

use phasync\Psr\Response;
use phasync\Psr\StringStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\EventStreamResponse;
use Swerve\ServerSentEvents;
use Swerve\Swerve;
use Swerve\WebSocket;
use Swerve\WebSocketResponse;

if ($ping = \getenv('SWERVE_TEST_PING')) {
    WebSocket::$pingInterval = (float) $ping;
}

function rt_send(int $status, string $body): ResponseInterface
{
    return new Response($status, ['Content-Type' => 'text/plain'], new StringStream($body));
}

while (true) {
    Swerve::accept(null, (new class {
        /** WebSocket and SSE handlers still running, see /live. */
        private array $live = ['news' => 0, 'sse' => 0];

        /** @var list<array{int, string}> what the $onClose of /websocket-events saw, see /ws-closes */
        private array $closes = [];

        /** Messages /websocket-flood has handed to send(), see /progress. */
        private int $sent = 0;

        public function handle(ServerRequestInterface $r): ResponseInterface
        {
            $path  = $r->getUri()->getPath();
            $query = $r->getQueryParams();

            switch ($path) {
                case '/hello':
                    return rt_send(200, 'Hello');
                case '/ext':
                    return rt_send(200, \extension_loaded('phasync') ? '1' : '0');
                case '/live':
                    return rt_send(200, \json_encode([\getmypid(), $this->live[$query['what']]]));
                case '/progress':
                    return rt_send(200, (string) $this->sent);
                case '/mem':
                    return rt_send(200, (string) \memory_get_peak_usage(true));
                case '/ws-closes':
                    return rt_send(200, \json_encode($this->closes));
                case '/publish':
                    Swerve::publish($query['topic'], $query['m']);

                    return rt_send(200, 'published');
                case '/publish-json':
                    Swerve::publish($query['topic'], ['m' => $query['m'], 'n' => 1, 'list' => [1, 2]]);

                    return rt_send(200, 'published');
                case '/publish-end':
                    Swerve::publish($query['topic'], ['end' => true]);

                    return rt_send(200, 'published');

                // An echo as a browser would use it: "bye" ends the callback (1000), "throw" throws (1011)
                case '/websocket':
                case '/websocket-accept': // the same mechanism: accept() has no raw ClientRequest to hand WebSocket::accept()
                    return WebSocketResponse::from($r, static function (WebSocket $ws) {
                        foreach ($ws as $message) {
                            if ('bye' === $message) {
                                return;
                            }
                            if ('throw' === $message) {
                                throw new RuntimeException('the WebSocket callback failed');
                            }
                            $ws->isBinary() ? $ws->sendBinary($message) : $ws->send($message);
                        }
                    });
                // Subprotocols the server speaks, in its order of preference; tells which was chosen
                case '/websocket-sub':
                    return WebSocketResponse::from($r, static function (WebSocket $ws) {
                        $ws->send($ws->subprotocol ?? 'none');
                    }, subprotocols: ['chat.v2', 'chat.v1']);
                // What an adapter does: decide from the parts of the request, add a header to the 101
                case '/websocket-adapter':
                    return WebSocketResponse::from($r, static function (WebSocket $ws) {
                        $ws->send($ws->subprotocol ?? 'none');
                        foreach ($ws as $message) {
                            $ws->send($message);
                        }
                    }, subprotocols: ['chat.v1'], maxMessage: 10)->withHeader('x-added', 'by middleware');
                case '/websocket-origin':
                    return WebSocketResponse::from($r, static function (WebSocket $ws) {
                        $ws->send('welcome');
                        foreach ($ws as $message) {
                            $ws->send($message);
                        }
                    }, origins: ['https://example.com', 'https://www.example.com']);
                case '/websocket-limit':
                    return WebSocketResponse::from($r, static function (WebSocket $ws) {
                        foreach ($ws as $message) {
                            $ws->send(\strlen($message) . ' bytes');
                        }
                    }, maxMessage: 1000);
                // A WebSocket that only sends: what is published to 'news' goes to the browser
                case '/websocket-news':
                    return WebSocketResponse::from($r, function (WebSocket $ws) {
                        $subscription = Swerve::subscribe('news'); // waits for the master: counted as live once subscribed
                        ++$this->live['news'];
                        try {
                            foreach ($subscription as $message) {
                                $ws->send($message);
                            }
                        } finally {
                            --$this->live['news'];
                        }
                    });
                // A WebSocket that forwards 'feed' and ends itself on a message saying it ends
                case '/websocket-feed':
                    return WebSocketResponse::from($r, static function (WebSocket $ws) {
                        foreach (Swerve::subscribe('feed') as $message) {
                            if ($message instanceof \Swerve\Util\SealedObject && ($message->end ?? false)) {
                                return;
                            }
                            $ws->send(\is_string($message) ? $message : \json_encode($message));
                        }
                    });
                // A chat: what the browser sends is published (events in, sequential code out); "end" published ends it
                case '/websocket-chat':
                    return WebSocketResponse::from($r, static function (WebSocket $ws) {
                        $ws->onMessage->listen(static function (string $data) {
                            Swerve::publish('chat', $data);
                        });
                        foreach (Swerve::subscribe('chat') as $out) {
                            if ('end' === $out) {
                                return;
                            }
                            $ws->send($out);
                        }
                    });
                // Events: echoes with the binary flag ("t:"/"b:"); "end" calls end(4000, 'server done'), "throw" throws
                case '/websocket-events':
                    return WebSocketResponse::from($r, function (WebSocket $ws) {
                        $ws->onClose->listen(function (int $code, string $reason) {
                            $this->closes[] = [$code, $reason];
                        });
                        $ws->onMessage->listen(static function (string $data, bool $binary) use ($ws) {
                            if ('end' === $data) {
                                $ws->end(4000, 'server done');
                            } elseif ('throw' === $data) {
                                throw new RuntimeException('the WebSocket listener failed');
                            } else {
                                $ws->send(($binary ? 'b:' : 't:') . \bin2hex($data));
                            }
                        });
                        \phasync::sleep(3600); // until the connection ends
                    });
                // The first message goes to a once() listener, the rest are pulled
                case '/websocket-once':
                    return WebSocketResponse::from($r, static function (WebSocket $ws) {
                        $first = new stdClass();
                        $ws->onMessage->once(static function (string $data) use ($ws, $first) {
                            $ws->send("once:$data");
                            \phasync::raiseFlag($first);
                        });
                        \phasync::awaitFlag($first);
                        foreach ($ws as $message) {
                            $ws->send("pull:$message");
                        }
                    });
                // receive() while $onMessage has listeners
                case '/websocket-mixed':
                    return WebSocketResponse::from($r, static function (WebSocket $ws) {
                        $ws->onMessage->listen(static fn () => null);
                        try {
                            $ws->receive();
                        } catch (LogicException $e) {
                            $ws->send('LogicException');
                        }
                    });
                // 100 kB binary messages: sends 1000 of them, as fast as the client takes them
                case '/websocket-flood':
                    return WebSocketResponse::from($r, function (WebSocket $ws) {
                        $this->sent = 0;
                        $chunk      = \str_repeat('x', 100_000);
                        for ($i = 0; $i < 1000; ++$i) {
                            $ws->sendBinary($chunk);
                            ++$this->sent;
                        }
                        $ws->send('flooded');
                        foreach ($ws as $ignored) {
                        }
                    });
                // Ten coroutines send 30 messages each ("who:n:filler") at once; then the callback returns
                case '/websocket-concurrent':
                    return WebSocketResponse::from($r, static function (WebSocket $ws) {
                        $fibers = [];
                        for ($who = 0; $who < 10; ++$who) {
                            $fibers[] = \phasync::go(static function () use ($ws, $who) {
                                for ($n = 0; $n < 30; ++$n) {
                                    $ws->send("$who:$n:" . \str_repeat('x', ($who * 7919 + $n * 104729) % 70000));
                                }
                            });
                        }
                        foreach ($fibers as $fiber) {
                            \phasync::await($fiber);
                        }
                    });
                // Takes no message for 1.5 s, then counts them: "done" answers how many arrived
                case '/websocket-slow':
                    return WebSocketResponse::from($r, static function (WebSocket $ws) {
                        \phasync::sleep(1.5);
                        $n = 0;
                        foreach ($ws as $message) {
                            if ($ws->isBinary()) {
                                ++$n;
                            } else {
                                $ws->send("received:$n");
                            }
                        }
                    });

                // Server-Sent Events: ?n= events, ?ms= apart, "data: <i>" each; ?wait= ms before the first
                case '/sse':
                    return EventStreamResponse::stream(static function (ServerSentEvents $sse) use ($query) {
                        \phasync::sleep((int) ($query['wait'] ?? 0) / 1000);
                        for ($i = 0; $i < (int) $query['n']; ++$i) {
                            $i > 0 && \phasync::sleep((int) $query['ms'] / 1000);
                            $sse->send((string) $i);
                        }
                    });
                // Every field at once, and what needs sanitising
                case '/sse-fields':
                    return EventStreamResponse::stream(static function (ServerSentEvents $sse) {
                        $sse->send("one\ntwo\r\nthree\rfour", 'greeting', '7', 1500);
                        $sse->send('');
                        $sse->send('x', "ev\r\nil: injected", "id\r\nbad\0");
                        $sse->comment('keep-alive');
                        $sse->comment("two\nlines");
                    });
                // What the client says it last saw
                case '/sse-last':
                    return EventStreamResponse::stream(static function (ServerSentEvents $sse) {
                        $sse->send(\json_encode($sse->lastEventId()));
                    });
                // What HEAD gets: the head, and no callback at all (EventStreamResponse's own contract)
                case '/sse-head':
                    return EventStreamResponse::stream(static function (ServerSentEvents $sse) {
                        $sse->send('nobody reads this');
                    });
                // An endless stream: a heartbeat every 50 ms, until the client leaves; /live?what=sse counts them
                case '/sse-live-stream':
                    return EventStreamResponse::stream(function (ServerSentEvents $sse) {
                        ++$this->live['sse'];
                        try {
                            while (true) {
                                $sse->comment('beat');
                                \phasync::sleep(0.05);
                            }
                        } finally {
                            --$this->live['sse'];
                        }
                    });
                default:
                    return rt_send(404, 'Not found');
            }
        }
    })->handle(...));
}
