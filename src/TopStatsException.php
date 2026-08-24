<?php

declare(strict_types=1);

namespace TopStats\Analytics;

/**
 * Base class for everything the SDK reports or throws. `errorCode` is a stable
 * machine-readable string: `validation`, `event_too_large`, `queue_overflow`,
 * `client_shut_down`, `api_error`, `network_error` or `unknown`.
 */
class TopStatsException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
