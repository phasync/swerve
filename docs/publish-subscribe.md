# Publish and subscribe

`Swerve::publish()` and `Swerve::subscribe()` carry messages between the workers: a message
published in any worker reaches the subscribers of its topic in every worker, the publishing one
included. The messages go in a log in APCu, the memory every worker shares; each worker with
subscribers reads it, and hands each message to its subscribers of the topic.

```php
use Swerve\Swerve;

Swerve::publish('room:lobby', json_encode(['user' => 'ann', 'text' => 'hi']));

foreach (Swerve::subscribe('room:lobby') as $message) {
    // every message published to room:lobby from the moment subscribe() returned
}
```

## What it promises

- **One order**: every message of every publisher has its place in the log, and every subscriber,
  in every worker, receives them in that order. Two requests that update a row one after the
  other may still publish in the other order: when a message carries state (a count, a
  document), give it a version from storage (a counter incremented in the same transaction) and
  let clients ignore one older than what they have.
- **At most once, never silently less**: a subscription receives what is published after it was
  made; there is no history and no retry. The log is a ring of `--pubsub-ring` messages (8192 by
  default): a worker that falls a whole ring behind (its event loop stuck in blocking code) loses
  messages, and its subscribers get a `Swerve\SubscriberLagException` where they were. A warning
  is logged from 3/4 of the ring. Keep what must not be lost in storage, and use publish/subscribe
  to tell the others it changed.
- **One machine**: swerve on several servers needs Redis, NATS or the like between them.
- **Outside swerve** (a test, a script), messages stay in that process.

## Subscriptions

`Swerve::subscribe(string $topic, float $maxLag = 30.0, ?float $heartbeat = null): Subscription`

- It receives from the moment `subscribe()` returns, not from the first iteration: subscribe
  before you tell anyone you are listening.
- It ends when its last reference goes: a `break` out of the loop, the variable going out of
  scope, the coroutine holding it ending, or `phasync::cancel()` of that coroutine. There is no
  unsubscribe to forget.
- Iterating waits for messages. With `heartbeat: 15`, it also yields `null` after 15 s without
  one: for a producer that sends keep-alives while it waits.
- When the worker stops (a shutdown or reload), the loop throws a `Swerve\WorkerStoppingException` at its
  wait, as every wait does then; a subscription made while draining ends at once.
  `Swerve::draining()` says whether the worker drains.
- A message is kept once per worker, however many subscribe, until the slowest subscriber read
  it. A subscriber that falls more than `$maxLag` seconds behind the publisher gets a
  `Swerve\SubscriberLagException` from its loop, as does one whose worker lost messages: a client that can't keep up is
  better disconnected (it reconnects and catches up from storage) than keeping every message
  since.
- A worker without subscribers reads nothing; one with subscribers reads every message, and
  skips the topics it has none for.
  Topics like `room:42` or `user:1234` are fine; a topic lives while it has subscribers.
- Topics starting with `"\0"` are swerve's own: `publish()` and `subscribe()` refuse them.

## Publishing

`Swerve::publish(string $topic, string $message): void`

- Topics are 1 to 255 bytes, messages at most 128 KiB; larger ones throw
  `InvalidArgumentException`.
- It returns once the message is in the log, not when it is delivered, and never suspends the
  caller: also in a `finally` of a cancelled coroutine, and directly in `swerve.php`.
- Every message travels as JSON: encoded once where it is published, decoded once in each
  worker, and every subscriber gets the value that was published, shared. A string stays a
  string (`'{}'` too), a list an array; an object (an array with keys) arrives as a read-only
  `Swerve\Util\SealedObject`: read `$message->end`, and no subscriber can change what the others see. `null` is
  refused: a subscription with a heartbeat yields `null` for "nothing came".
- Messages go to the subscribers in the workers, never to a browser by themselves. Publish
  values as they are (`Swerve::publish('game', ['kill', $playerId])`), so 10,000 subscribers don't
  each decode a string. When subscribers pass a message on unchanged to their
  SSE clients, publish the string those clients should get: it is encoded once, by the publisher,
  not by every subscriber.

## Binary messages

`Swerve::publishRaw(string $topic, string $bytes): void` sends `$bytes` unchanged: for messages
`publish()` cannot carry, such as a binary WebSocket frame, which is not valid UTF-8 and so
cannot be JSON. A subscriber gets exactly `$bytes`, as a plain string, never decoded. The same
promises as `publish()` apply (one order, delivery, limits); a topic may mix `publish()` and
`publishRaw()` messages, each subscriber getting each one in its own shape.

```php
Swerve::publishRaw('frames', $binaryWebSocketFrame);
```

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

The log lives in APCu beside the cache: `--pubsub-ring` messages of up to 128 KiB each must fit
in `apc.shm_size` (128M by default; `-d apc.shm_size=512M`) with the cache. When APCu runs out
it empties itself, and the subscribers of every worker get a `Swerve\SubscriberLagException`.
A publish costs an `apcu_inc()`, an `apcu_store()` and a one-byte datagram to each worker with
subscribers; a slow subscriber holds up nobody but itself.

Next: [Command line](command-line.md).
