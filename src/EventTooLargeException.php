<?php

declare(strict_types=1);

namespace TopStats\Analytics;

/** Over `MAX_EVENT_BYTES`. Sending it would cost a whole request that 413s. */
final class EventTooLargeException extends TopStatsException
{
    public function __construct(
        public readonly string $eventName,
        public readonly int $byteLength,
        public readonly int $maxEventBytes,
    ) {
        parent::__construct(
            sprintf(
                'Event "%s" is %d bytes, over the %d byte limit, so it was dropped',
                $eventName,
                $byteLength,
                $maxEventBytes,
            ),
            'event_too_large',
        );
    }
}
