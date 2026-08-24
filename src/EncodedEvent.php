<?php

declare(strict_types=1);

namespace TopStats\Analytics;

/**
 * Serialised once, at capture time. The queue then splits batches on the exact
 * byte count the API will measure, and no event is encoded twice.
 */
final class EncodedEvent
{
    public function __construct(
        public readonly string $name,
        public readonly string $json,
        public readonly int $byteLength,
    ) {
    }
}
