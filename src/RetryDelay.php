<?php

declare(strict_types=1);

namespace TopStats\Analytics;

final class RetryDelay
{
    /**
     * A server-provided Retry-After wins outright. Otherwise, exponential
     * backoff where half of each window is fixed and half is random: the rate
     * limit is keyed on the client address, so every process behind one egress
     * IP hits the same 429 at the same moment, and without jitter they would
     * all retry in lockstep and do it again.
     */
    public static function secondsBeforeRetry(int $attemptIndex, ?float $retryAfterSeconds): float
    {
        if ($retryAfterSeconds !== null) {
            return $retryAfterSeconds;
        }

        $ceiling = min(
            Constants::INITIAL_RETRY_DELAY_SECONDS * (2 ** $attemptIndex),
            Constants::MAX_RETRY_DELAY_SECONDS,
        );

        $randomFraction = mt_rand() / mt_getrandmax();

        return $ceiling / 2 + $randomFraction * ($ceiling / 2);
    }

    private function __construct()
    {
    }
}
