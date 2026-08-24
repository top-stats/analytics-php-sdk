<?php

declare(strict_types=1);

namespace TopStats\Analytics;

/** A value the API would have rejected with a 400, caught before it was sent. */
final class ValidationException extends TopStatsException
{
    public function __construct(
        string $message,
        public readonly string $field,
    ) {
        parent::__construct($message, 'validation');
    }
}
