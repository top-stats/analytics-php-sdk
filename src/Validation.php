<?php

declare(strict_types=1);

namespace TopStats\Analytics;

/**
 * The API validates the same rules and answers a 400 whose message is a
 * pretty-printed zod issue array. Checking here turns that into a named field
 * and a sentence, and costs the caller nothing when the value is fine.
 */
final class Validation
{
    public static function validateEventName(string $name): void
    {
        if ($name === '') {
            throw new ValidationException('The event name must not be empty', 'name');
        }

        $length = self::utf16Length($name);

        if ($length > Constants::MAX_EVENT_NAME_LENGTH) {
            throw new ValidationException(
                sprintf(
                    'The event name must be at most %d characters, got %d',
                    Constants::MAX_EVENT_NAME_LENGTH,
                    $length,
                ),
                'name',
            );
        }
    }

    /**
     * @param non-empty-array<array-key, mixed> $properties
     */
    public static function validateProperties(array $properties): void
    {
        // A list serialises to a JSON array, and `properties` must be an object.
        if (array_is_list($properties)) {
            throw new ValidationException(
                'Properties must be a map with string keys, not a list',
                'properties',
            );
        }

        foreach (array_keys($properties) as $key) {
            self::validatePropertyKey((string) $key);
        }
    }

    private static function validatePropertyKey(string $key): void
    {
        if ($key === '') {
            throw new ValidationException('A property key must not be empty', 'properties');
        }

        $length = self::utf16Length($key);

        if ($length > Constants::MAX_PROPERTY_KEY_LENGTH) {
            throw new ValidationException(
                sprintf(
                    'Property key "%s" is %d characters, over the %d character limit',
                    substr($key, 0, 32),
                    $length,
                    Constants::MAX_PROPERTY_KEY_LENGTH,
                ),
                'properties',
            );
        }
    }

    public static function validateReservedField(mixed $value, string $field, int $maxLength): string
    {
        if (!is_string($value)) {
            throw new ValidationException(sprintf('%s must be a string', $field), $field);
        }

        $length = self::utf16Length($value);

        if ($length > $maxLength) {
            throw new ValidationException(
                sprintf('%s must be at most %d characters, got %d', $field, $maxLength, $length),
                $field,
            );
        }

        return $value;
    }

    /** Trims like the API does, so the caller sees the length the API will see. */
    public static function validatedEvaluateKey(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new ValidationException(sprintf('%s must be a string', $field), $field);
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new ValidationException(sprintf('%s must not be empty', $field), $field);
        }

        $length = self::utf16Length($trimmed);

        if ($length > Constants::MAX_EVALUATE_KEY_LENGTH) {
            throw new ValidationException(
                sprintf(
                    '%s must be at most %d characters, got %d',
                    $field,
                    Constants::MAX_EVALUATE_KEY_LENGTH,
                    $length,
                ),
                $field,
            );
        }

        return $trimmed;
    }

    /**
     * @param array<array-key, mixed> $keys
     *
     * @return list<string>
     */
    public static function validatedFlagKeys(array $keys): array
    {
        if (count($keys) > Constants::MAX_FLAG_KEYS_PER_REQUEST) {
            throw new ValidationException(
                sprintf(
                    'keys may hold at most %d entries, got %d',
                    Constants::MAX_FLAG_KEYS_PER_REQUEST,
                    count($keys),
                ),
                'keys',
            );
        }

        $validated = [];

        foreach ($keys as $key) {
            $validated[] = self::validatedFlagKey($key);
        }

        return $validated;
    }

    /**
     * The API constrains `keys` only by its 200 entry cap, so anything that is
     * a usable string is passed through. Narrowing the charset here would
     * refuse keys the API is happy to evaluate.
     */
    public static function validatedFlagKey(mixed $key): string
    {
        if (!is_string($key)) {
            throw new ValidationException('A flag key must be a string', 'keys');
        }

        $trimmed = trim($key);

        if ($trimmed === '') {
            throw new ValidationException('A flag key must not be empty', 'keys');
        }

        return $trimmed;
    }

    /**
     * The API measures string limits in JavaScript string length, which is
     * UTF-16 code units, not bytes and not code points. Counted here from the
     * UTF-8 bytes directly so no mbstring extension is needed: one unit per
     * leading byte, plus one more for each four-byte sequence, whose code
     * point needs a surrogate pair.
     */
    public static function utf16Length(string $value): int
    {
        $length = 0;
        $byteCount = strlen($value);

        for ($index = 0; $index < $byteCount; $index++) {
            $byte = ord($value[$index]);

            if (($byte & 0xC0) !== 0x80) {
                $length++;
            }

            if ($byte >= 0xF0) {
                $length++;
            }
        }

        return $length;
    }

    private function __construct()
    {
    }
}
