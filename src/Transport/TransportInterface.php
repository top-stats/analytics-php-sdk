<?php

declare(strict_types=1);

namespace TopStats\Analytics\Transport;

use TopStats\Analytics\NetworkException;

/**
 * One HTTP attempt, no retries: the retry policy lives in the client so a test
 * transport sees every attempt. A response with any status is returned as a
 * TransportResponse; only a request that never produced a response throws.
 */
interface TransportInterface
{
    /**
     * @param array<string, string> $headers
     *
     * @throws NetworkException when no response was produced: DNS, connection, or timeout
     */
    public function post(string $url, string $body, array $headers, float $timeoutSeconds): TransportResponse;
}
