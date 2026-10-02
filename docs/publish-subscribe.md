# Publish and subscribe

Workers share no memory. `Swerve::publish()` and `Swerve::subscribe()` carry messages between
them: a message published in any worker reaches the subscribers of its topic in every worker,
the publishing one included.

```php
use Swerve\Swerve;

Swerve::publish('room:lobby', json_encode(['user' => 'ann', 'text' => 'hi']));

foreach (Swerve::subscribe('room:lobby') as $message) {
    // every message published to room:lobby from the moment subscribe() returned
}
```

## What it promises

- **Order**: the messages of one publisher arrive in the order it published them, for all its
  subscribers. There is no order between workers: the publisher writes straight into the inbox of each worker with
  subscribers, and two subscribers may see messages from different workers in different orders,
  as may two requests that update a row one after the other. When a message carries state (a
  count, a document), give it a version from storage (a counter incremented in the same
  transaction) and let clients ignore one older than what they have. For one order across all
  subscribers, see `OrderedChannel` below.
- **At most once**: to the subscriptions that exist when the message reaches their worker. There
  is no history and no retry: a subscriber that starts later, a worker started after a reload,
  a recycle or a crash, see nothing sent before. Keep what must not be lost in storage, and use
  publish/subscribe to tell the others it changed.
- **One machine**: swerve on several servers needs Redis, NATS or the like between them.
- **Without the master** (swerve embedded in your own process), messages stay in that process.

## Subscriptions

`Swerve::subscribe(string $topic, float $maxLag = 30.0, ?float $heartbeat = null): Subscription`

- It receives from the moment `subscribe()` returns, not from the first iteration: subscribe
  before you tell anyone you are listening.
- It ends when its last reference goes: a `break` out of the loop, the variable going out of
  scope, the coroutine holding it ending, or `phasync::cancel()` of that coroutine. There is no
  unsubscribe to forget.
- Iterating waits for messages. With `heartbeat: 15`, it also yields `null` after 15 s without
  one: for a producer that sends keep-alives while it waits.
- The loop ends when the worker drains (a shutdown, reload or recycle), so that responses
  fed by it end too; a subscription made while draining ends at once. `Swerve::draining()`
  says whether the worker drains.
- A message is kept once per worker, however many subscribe, until the slowest subscriber read
  it. A subscriber that falls more than `$maxLag` seconds behind gets a
  `Swerve\SubscriberLagException` from its loop: a client that can't keep up is
  better disconnected (it reconnects and catches up from storage) than keeping every message
  since.
- A topic costs nothing in a worker without subscribers: messages to it are dropped there.
  Topics like `room:42` or `user:1234` are fine; a topic lives while it has subscribers.

## OrderedChannel

`new \Swerve\OrderedChannel(string $name)`, for many publishers that need one common order: every
subscriber, in every worker, receives the messages in one and the same order, and each
publisher's own messages keep their order in it. A broadcast like `publish()`, apart from the
plain topic of the same name: `write()` does not reach `Swerve::subscribe()`, nor the reverse.

```php
$ledger = new \Swerve\OrderedChannel('ledger');
$ledger->write(['debit', 12]);

foreach ($ledger as $entry) { ... }                       // subscribes here
$subscription = $ledger->subscribe(maxLag: 30.0, heartbeat: null);
```

- Every publishing worker appends the message to a file in a directory of the master's, in one
  `write()` on an `O_APPEND` descriptor; the kernel's order of appends is the order. Each worker
  with subscribers reads the file as it grows, and wakes on a datagram from the publisher. A
  worker that dies after it appended loses nothing: the message is in the file.
- Nothing is written while the channel has no subscriber, and the writing stops a second
  or so after the last one ends. Such messages are lost, as plain ones are: a subscriber sees
  what is written after `subscribe()` returned.
- The files are in a directory of the master's (mode 0700, under `$SWERVE_TMPDIR`, by default
  the system's temporary directory; tmpfs such as `/dev/shm` is fine). They are not synced and
  hold nothing beyond the master's life. A file covers 10 s, and one is unlinked when it is more
  than 30 s old: this is also how far a subscriber can be behind and still get its messages.
- A subscriber that is more than `$maxLag` behind gets a `SubscriberLagException`, as for plain
  topics; so does one whose worker did not run its event loop for 30 s, and its files were
  removed before it read them.
- A wake-up is best effort, like any message: a reader that missed one finds the message at its
  next look, at most a second later. The one way left for an inversion is a publisher that stalls
  for more than a second between reading the clock and appending; its message may then be missed
  by readers that have moved on.
- Topics starting with `"\0"` are swerve's own: `publish()`, `subscribe()` and the channel refuse them. Available once
  the worker serves, see [what works where](bootstrap.md#what-works-where). Without the master, it works in the process,
  in publishing order.

## Publishing

`Swerve::publish(string $topic, string $message): void`

- Topics are 1 to 255 bytes, messages at most 128 KiB; larger ones throw
  `InvalidArgumentException`.
- It returns once the message is on its way, not when it is delivered.
- Every message travels as JSON: encoded once where it is published, decoded once in each
  worker, and every subscriber gets the value that was published, shared. A string stays a
  string (`'{}'` too), a list an array; an object (an array with keys) arrives as a read-only
  `Swerve\Util\SealedObject`: read `$message->end`, and no subscriber can change what the others see. `null` is
  refused: a subscription with a heartbeat yields `null` for "nothing came".
- Messages go to the subscribers in the workers, never to a browser by themselves. Publish
  values as they are (`Swerve::publish('game', ['kill', $playerId])`), so 10,000 subscribers don't
  each decode a string. When subscribers pass a message on unchanged to their WebSocket or SSE
  clients, publish the string those clients should get: it is encoded once, by the publisher,
  not by every subscriber.

## Patterns

**State on connect, then updates.** Almost every realtime page needs the current state first,
then the changes. Subscribe first, then read the state from storage, then forward messages:
subscribing first means no change is missed in between. A message may then be older than the
state already sent, so versions (see *Order* above) make the client keep the newest:

```php
$subscription = Swerve::subscribe('poll');
$state        = $db->query('SELECT version, counts FROM poll')->fetch(); // after subscribing
$out->append('data: ' . json_encode($state) . "\n\n");
foreach ($subscription as $message) {       // {"version": 42, "counts": [...]}
    $out->append("data: $message\n\n");     // the browser drops versions <= the one it has
}
```

**A chat room**: every connection subscribes to `room:<id>`; a message from a client is
validated, stored, and published; each connection forwards what it receives. See the
[examples](../examples).

**Presence** (who is online): each connection publishes `joined` and `left` events to the room.
Each worker only knows its own connections, so a list of everyone online needs a shared store
(a table with a heartbeat, or Redis with expiry); publish/subscribe tells the others to update.

**Cache invalidation**: publish the key that changed to `cache`; a coroutine in each worker,
started when the application loads, subscribes and drops its cached copy.

## Limits

A worker's inbox holds about 200 KB. A worker that stops reading it (its event loop stuck in
blocking code) does not hold up the publishers: after 0.1 s they drop what they could not
deliver to it and log a warning, and it gets what is published after it reads again.

`Swerve::publish()` works once the worker serves; before that, while `swerve.php` loads, it
throws `LogicException`. A coroutine started there may publish after its first wait: see
[what works where](bootstrap.md#what-works-where).

Next: [Command line](command-line.md).
