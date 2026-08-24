<?php

declare(strict_types=1);

namespace TopStats\Analytics;

/**
 * The API validates `_timestamp` with `z.iso.datetime()`, which rejects any
 * offset form: `+00:00` is a 400, only a literal `Z` passes. Everything is
 * therefore converted to UTC and formatted with milliseconds and a `Z`.
 */
final class Timestamp
{
    public static function toIsoString(mixed $value): string
    {
        $utc = self::toDateTime($value)->setTimezone(new \DateTimeZone('UTC'));

        // A year outside 0000 to 9999 would need the expanded ISO form, which
        // is Z suffixed and still a 400 server-side.
        if (preg_match('/^\d{4}$/', $utc->format('Y')) !== 1) {
            throw new ValidationException(
                sprintf(
                    'The timestamp year %s is outside the range the API accepts',
                    $utc->format('Y'),
                ),
                'timestamp',
            );
        }

        return $utc->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function toDateTime(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value)) {
            return self::parseDateTimeString($value);
        }

        throw new ValidationException(
            'The timestamp must be a DateTimeInterface or an ISO 8601 string',
            'timestamp',
        );
    }

    private static function parseDateTimeString(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new ValidationException(
                sprintf('The timestamp "%s" is not a valid date', substr($value, 0, 64)),
                'timestamp',
            );
        }
    }

    private function __construct()
    {
    }
}
