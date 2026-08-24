<?php

declare(strict_types=1);

namespace TopStats\Analytics;

use TopStats\Analytics\Transport\TransportResponse;

/** The API answered with a non-2xx status. */
final class ApiException extends TopStatsException
{
    public function __construct(
        public readonly int $status,
        public readonly string $responseMessage,
        public readonly bool $isRetryable,
        public readonly ?float $retryAfterSeconds,
    ) {
        parent::__construct(
            sprintf('The API responded %d: %s', $status, $responseMessage),
            'api_error',
        );
    }

    /** Only a 429 or a 5xx is worth sending again. Every other status is the payload's fault. */
    public static function fromResponse(TransportResponse $response): self
    {
        return new self(
            $response->status,
            self::toErrorMessage($response->body),
            $response->status === 429 || $response->status >= 500,
            self::parseRetryAfter($response->header('retry-after')),
        );
    }

    /**
     * Every API error is `{ statusCode, error, message }`. On a 400 the message
     * is a pretty-printed zod issue array, which is surfaced as-is rather than
     * parsed: it is an internal representation and it will change shape.
     */
    private static function toErrorMessage(string $body): string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return 'no response body';
        }

        return self::truncate(self::readMessageField($trimmed) ?? $trimmed);
    }

    private static function readMessageField(string $body): ?string
    {
        $parsed = json_decode($body, true);

        if (!is_array($parsed)) {
            return null;
        }

        $message = $parsed['message'] ?? null;

        return is_string($message) ? $message : null;
    }

    private static function truncate(string $value): string
    {
        if (strlen($value) <= Constants::MAX_ERROR_MESSAGE_LENGTH) {
            return $value;
        }

        return substr($value, 0, Constants::MAX_ERROR_MESSAGE_LENGTH) . '...';
    }

    private static function parseRetryAfter(?string $headerValue): ?float
    {
        if ($headerValue === null) {
            return null;
        }

        $trimmed = trim($headerValue);

        if ($trimmed === '') {
            return null;
        }

        if (is_numeric($trimmed)) {
            $seconds = (float) $trimmed;

            // A negative number is not a valid delay in either header form.
            return $seconds >= 0 ? min($seconds, Constants::MAX_RETRY_AFTER_SECONDS) : null;
        }

        return self::parseRetryAfterDate($trimmed);
    }

    private static function parseRetryAfterDate(string $headerValue): ?float
    {
        $timestamp = strtotime($headerValue);

        if ($timestamp === false) {
            return null;
        }

        $delaySeconds = $timestamp - time();

        if ($delaySeconds <= 0) {
            return 0.0;
        }

        return min((float) $delaySeconds, Constants::MAX_RETRY_AFTER_SECONDS);
    }
}
