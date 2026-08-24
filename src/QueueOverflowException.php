<?php

declare(strict_types=1);

namespace TopStats\Analytics;

/** The queue hit `maxQueueSize`, so the oldest events were dropped. */
final class QueueOverflowException extends TopStatsException
{
    public function __construct(
        public readonly int $droppedCount,
        public readonly int $maxQueueSize,
    ) {
        parent::__construct(
            sprintf(
                'Queue is full at %d events, so the %d oldest were dropped',
                $maxQueueSize,
                $droppedCount,
            ),
            'queue_overflow',
        );
    }
}
