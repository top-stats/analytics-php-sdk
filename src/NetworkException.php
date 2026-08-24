<?php

declare(strict_types=1);

namespace TopStats\Analytics;

/** The request never produced a response: DNS, connection, or timeout. */
final class NetworkException extends TopStatsException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 'network_error', $previous);
    }
}
