<?php

declare(strict_types=1);

namespace TopStats\Analytics;

final class FlagResult
{
    public const REASONS = ['rollout', 'boolean', 'disabled', 'no_actor', 'not_found'];

    /**
     * @param 'true'|'false' $variant mirrors `value` as a string
     * @param 'rollout'|'boolean'|'disabled'|'no_actor'|'not_found' $reason
     */
    public function __construct(
        public readonly bool $value,
        public readonly string $variant,
        public readonly string $reason,
    ) {
    }
}
