<?php

declare(strict_types=1);

namespace TopStats\Analytics;

/**
 * Both payload schemas are strict objects server-side, so an unknown top-level
 * key rejects the whole request. Every wire object is therefore built field by
 * field from an allowlist, never serialised from something the caller handed us.
 */
final class EventEncoder
{
    private const CONTEXT_KEYS = ['actor', 'actorLabel', 'source', 'timestamp'];

    /**
     * @param array<array-key, mixed> $properties
     * @param array<array-key, mixed> $context
     */
    public static function encode(
        string $name,
        array $properties,
        array $context,
        ?string $defaultSource,
    ): EncodedEvent {
        Validation::validateEventName($name);
        self::rejectUnknownContextKeys($context);

        $wireEvent = ['name' => $name];

        // An empty map is left off entirely: PHP cannot tell an empty map from
        // an empty list, and `properties: []` is a 400.
        if ($properties !== []) {
            Validation::validateProperties($properties);
            $wireEvent['properties'] = $properties;
        }

        $source = $context['source'] ?? $defaultSource;

        if ($source !== null) {
            $wireEvent['_source'] = Validation::validateReservedField(
                $source,
                '_source',
                Constants::MAX_SOURCE_LENGTH,
            );
        }

        if (array_key_exists('actor', $context)) {
            $wireEvent['_actor'] = Validation::validateReservedField(
                $context['actor'],
                '_actor',
                Constants::MAX_ACTOR_LENGTH,
            );
        }

        if (array_key_exists('actorLabel', $context)) {
            $wireEvent['_actorLabel'] = Validation::validateReservedField(
                $context['actorLabel'],
                '_actorLabel',
                Constants::MAX_ACTOR_LABEL_LENGTH,
            );
        }

        // Stamped here rather than left to the API: the API stamps one receive
        // time per request, so a buffered batch would land on a single instant.
        $wireEvent['_timestamp'] = Timestamp::toIsoString(
            $context['timestamp'] ?? new \DateTimeImmutable('now'),
        );

        $json = json_encode($wireEvent, Constants::JSON_ENCODE_FLAGS);

        if ($json === false) {
            throw new ValidationException(
                sprintf('Event "%s" could not be serialised as JSON: %s', $name, json_last_error_msg()),
                'properties',
            );
        }

        return new EncodedEvent($name, $json, strlen($json));
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private static function rejectUnknownContextKeys(array $context): void
    {
        foreach (array_keys($context) as $key) {
            if (!in_array($key, self::CONTEXT_KEYS, true)) {
                throw new ValidationException(
                    sprintf(
                        'Unknown context key "%s"; the context accepts actor, actorLabel, source and timestamp',
                        (string) $key,
                    ),
                    'context',
                );
            }
        }
    }

    private function __construct()
    {
    }
}
