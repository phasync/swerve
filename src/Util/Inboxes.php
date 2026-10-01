<?php

namespace Swerve\Util;

/**
 * The master's part of publish/subscribe: the inboxes, and who is subscribed.
 *
 * An inbox is a datagram socket pair the master makes before it forks any worker. The process
 * that holds the inbox reads one end; every other worker holds the other end and writes to it,
 * so a published message goes from the publisher straight to the workers with subscribers, never
 * through the master. A worker process gets a free inbox when it is forked, and the master takes
 * it back when the process is gone; a replacement started during a reload has an inbox of its own
 * while the old process drains, so there are twice as many inboxes as slots.
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
     * In the new worker process, right after the fork: close the read end of every inbox but its
     * own, and the write end of its own, so that only the owner reads an inbox and nobody writes
     * to itself.
     *
     * @return array{0: resource, 1: array<int, resource>} the inbox to read, and the others to write to
     */
    public function adopt(int $own): array
    {
        $peers = $this->write;
        foreach ($this->read as $inbox => $read) {
            if ($inbox !== $own) {
                \fclose($read);
            }
        }
        \fclose($peers[$own]);
        unset($peers[$own]);
        $read = $this->read[$own];
        $this->read = $this->write = [];

        return [$read, $peers];
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
