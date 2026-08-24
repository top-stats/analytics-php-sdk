<?php

declare(strict_types=1);

namespace TopStats\Analytics;

/**
 * A bounded FIFO of already-serialised events. Full means the oldest go, which
 * keeps a live dashboard current during a backlog and can never block a caller.
 */
final class EventQueue
{
    /** @var list<EncodedEvent> */
    private array $events = [];

    public function __construct(private readonly int $maxQueueSize)
    {
    }

    public function size(): int
    {
        return count($this->events);
    }

    /** Returns how many events were dropped to make room, normally zero. */
    public function enqueue(EncodedEvent $event): int
    {
        $this->events[] = $event;
        $overflow = count($this->events) - $this->maxQueueSize;

        if ($overflow <= 0) {
            return 0;
        }

        $this->events = array_slice($this->events, $overflow);

        return $overflow;
    }

    /**
     * Removes and returns the next request's worth of events.
     *
     * @return list<EncodedEvent>
     */
    public function takeBatch(int $maxBatchSize, int $maxBodyBytes): array
    {
        $count = $this->countThatFit($maxBatchSize, $maxBodyBytes);
        $batch = array_slice($this->events, 0, $count);
        $this->events = array_slice($this->events, $count);

        return $batch;
    }

    /**
     * Stops at the batch count cap or the body byte cap, whichever comes first.
     * The first event is always taken so a draining loop cannot stall, even if
     * a caller configures a body cap smaller than one event.
     */
    private function countThatFit(int $maxBatchSize, int $maxBodyBytes): int
    {
        $count = 0;
        $totalBytes = 0;

        foreach ($this->events as $event) {
            if ($count > 0 && $count >= $maxBatchSize) {
                return $count;
            }

            $commaBytes = $count;
            $projected = Constants::EVENTS_BODY_OVERHEAD_BYTES + $totalBytes + $event->byteLength + $commaBytes;

            if ($count > 0 && $projected > $maxBodyBytes) {
                return $count;
            }

            $totalBytes += $event->byteLength;
            $count++;
        }

        return $count;
    }
}
