<?php

declare(strict_types=1);

namespace TopStats\Analytics;

/** The API is trusted to be well formed, but never assumed to be. */
final class FlagResponseParser
{
    /**
     * @return array<string, FlagResult>
     */
    public static function parse(mixed $payload): array
    {
        if (!is_array($payload)) {
            throw self::unexpectedShape('the response is not an object');
        }

        $flags = $payload['flags'] ?? null;

        if (!is_array($flags)) {
            throw self::unexpectedShape('the response has no "flags" object');
        }

        $results = [];

        foreach ($flags as $key => $value) {
            $results[(string) $key] = self::toFlagResult((string) $key, $value);
        }

        return $results;
    }

    private static function toFlagResult(string $key, mixed $value): FlagResult
    {
        if (!is_array($value)) {
            throw self::unexpectedShape(sprintf('flag "%s" is not an object', $key));
        }

        $flagValue = $value['value'] ?? null;

        if (!is_bool($flagValue)) {
            throw self::unexpectedShape(sprintf('flag "%s" has no boolean "value"', $key));
        }

        $variant = $value['variant'] ?? null;

        if ($variant !== 'true' && $variant !== 'false') {
            throw self::unexpectedShape(sprintf('flag "%s" has an unknown variant', $key));
        }

        $reason = $value['reason'] ?? null;

        if (!is_string($reason) || !in_array($reason, FlagResult::REASONS, true)) {
            throw self::unexpectedShape(sprintf('flag "%s" has an unknown reason', $key));
        }

        /** @var 'rollout'|'boolean'|'disabled'|'no_actor'|'not_found' $reason */
        return new FlagResult($flagValue, $variant, $reason);
    }

    private static function unexpectedShape(string $detail): TopStatsException
    {
        return new TopStatsException(
            sprintf('The flag evaluation response was not understood: %s', $detail),
            'api_error',
        );
    }

    private function __construct()
    {
    }
}
