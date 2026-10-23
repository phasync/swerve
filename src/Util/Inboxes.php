<?php

namespace Swerve\Util;

/**
 * The master's part of publish/subscribe: the inboxes, and who is subscribed.
 *
 * An inbox is a datagram socket pair the master makes before it proc_open's the worker-parent. The
 * process that holds the inbox reads one end; every other worker holds the other end and writes
 * to it, so a published message goes from the publisher straight to the workers with subscribers,
 * never through the master. Both ends of every inbox are inherited by the worker-parent (see
 * Cluster, Inboxes::streams()), and so by every lifecycle it forks; a lifecycle learns which
 * inbox is its own at hello, and re-opens that descriptor (and the others' write ends) itself,
 * each time it starts (see inc/lifecycle.php). The master takes an inbox back when the
 * lifecycle's connection ends; a replacement lifecycle (a reload, a recycle with upgraded
 * connections) has an inbox of its own while the old one drains or lingers, so there are four
 * times as many inboxes as slots (see Cluster::MAX_LINGERING).
 *
 * The master alone knows which inboxes have subscribers on a topic, as a bitmap: bit N is set
 * while the process in inbox N has a subscription. A worker asks for a topic's bitmap before it
 * publishes to it and keeps the answer until the master says to forget it, which it does
 * whenever the bitmap changes, before it answers the worker that changed it. A worker never
 * writes a bitmap: it asks to subscribe or to unsubscribe, and the master sets or clears the bit
 * of the inbox the request came from. The wire is the cache's (see Cache): requests and replies
 * on the worker's pipe to the master.
 *
 * @internal
 */
final class Inboxes
{
    /** Internal topics: a worker's requests and the master's replies, and the topics to forget. */
    public const TOPIC  = "\0subs";
    public const FORGET = "\0subs-forget";

    /** @var array<int, resource> */
    private array $read = [];
    /** @var array<int, resource> */
    private array $write = [];
    /** @var list<int> inboxes no process holds */
    private array $free;
    /** @var array<string, string> topic => bitmap: bit N set while inbox N has subscribers */
    private array $bitmaps = [];

    public function __construct(int $count)
    {
        for ($inbox = 0; $inbox < $count; ++$inbox) {
            [$this->read[$inbox], $this->write[$inbox]] = System::socketPair(\SOCK_DGRAM);
            \stream_set_blocking($this->read[$inbox], false);
            \stream_set_blocking($this->write[$inbox], false);
        }
        $this->free = \array_keys($this->read);
    }

    /**
     * Hand out a free inbox, emptied of what was written for the process that had it before;
     * null when every one is held (several workers of a slot are draining).
     */
    public function take(): ?int
    {
        $inbox = \array_shift($this->free);
        if (null !== $inbox) {
            while (false !== @\stream_socket_recvfrom($this->read[$inbox], Topics::MAX_DATAGRAM)) {
            }
            \error_clear_last();
        }

        return $inbox;
    }

    /** The process that held $inbox is gone. */
    public function release(int $inbox): void
    {
        $this->free[] = $inbox;
    }

    /**
     * The read end of every inbox, and the write end of every inbox: for the master to pass to
     * the worker-parent process (see Cluster), at fixed descriptor numbers a lifecycle re-opens
     * with `php://fd/N` (see inc/lifecycle.php). Replaces adopt(), which closed the fork's unused
     * ends directly; a proc_open'd process can't do that for its own future (forked) children,
     * so each lifecycle closes what it does not need itself, by descriptor number.
     *
     * @return array{0: array<int, resource>, 1: array<int, resource>}
     */
    public function streams(): array
    {
        return [$this->read, $this->write];
    }

    /**
     * In a lifecycle, right after hello told it which inbox is its own: re-open every inbox's
     * read and write descriptor by number (see streams(), Cluster::startWorkerParent(), the
     * SWERVE_INBOX_* env), then close what it does not need (every other inbox's read end, and
     * its own write end), exactly as the old fork-time adopt() did, so that only the owner reads
     * its inbox and nobody writes to itself.
     *
     * @return array{0: resource, 1: array<int, resource>} the inbox to read, and the others to write to
     */
    public static function adoptFromFds(int $own, int $fdBase, int $count): array
    {
        $read = $write = [];
        for ($inbox = 0; $inbox < $count; ++$inbox) {
            $read[$inbox]  = \fopen('php://fd/' . ($fdBase + $inbox), 'r+');
            $write[$inbox] = \fopen('php://fd/' . ($fdBase + $count + $inbox), 'r+');
            \stream_set_blocking($read[$inbox], false);
            \stream_set_blocking($write[$inbox], false);
        }
        $peers = $write;
        foreach ($read as $inbox => $stream) {
            if ($inbox !== $own) {
                \fclose($stream);
            }
        }
        \fclose($peers[$own]);
        unset($peers[$own]);

        return [$read[$own], $peers];
    }

    /**
     * A worker's request: the request id (4 bytes), an operation and the topic. `g` gets the
     * topic's bitmap, `+` subscribes the inbox the request came from, `-` unsubscribes it. A
     * change calls $forget with the topic first, so that every worker has forgotten the old
     * bitmap before the one who changed it hears of it. Returns the reply: the request id and the
     * bitmap; null for id 0, which asks for none (a worker that does not read its pipe yet).
     *
     * @param \Closure(string $topic): void $forget
     */
    public function serve(int $inbox, string $request, \Closure $forget): ?string
    {
        $op    = $request[4];
        $topic = \substr($request, 5);
        if ('g' !== $op) {
            $this->set($topic, $inbox, '+' === $op, $forget);
        }

        return "\0\0\0\0" === \substr($request, 0, 4) ? null : \substr($request, 0, 4) . ($this->bitmaps[$topic] ?? '');
    }

    /**
     * The process in $inbox has no subscriptions any more: it drains, or is gone.
     *
     * @param \Closure(string $topic): void $forget
     */
    public function leave(int $inbox, \Closure $forget): void
    {
        foreach ($this->bitmaps as $topic => $_) {
            $this->set((string) $topic, $inbox, false, $forget);
        }
    }

    /**
     * @param \Closure(string $topic): void $forget
     */
    private function set(string $topic, int $inbox, bool $on, \Closure $forget): void
    {
        $bitmap = $this->bitmaps[$topic] ?? '';
        $byte   = $inbox >> 3;
        $mask   = 1 << ($inbox & 7);
        $was    = $byte < \strlen($bitmap) ? \ord($bitmap[$byte]) : 0;
        if ($on === (bool) ($was & $mask)) {
            return;
        }
        $bitmap        = \str_pad($bitmap, $byte + 1, "\0");
        $bitmap[$byte] = \chr($on ? $was | $mask : $was & ~$mask);
        $bitmap        = \rtrim($bitmap, "\0");
        if ('' === $bitmap) {
            unset($this->bitmaps[$topic]);
        } else {
            $this->bitmaps[$topic] = $bitmap;
        }
        $forget($topic);
    }
}
