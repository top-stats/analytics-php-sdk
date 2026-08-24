<?php

declare(strict_types=1);

namespace TopStats\Analytics;

/**
 * Every limit below mirrors a server-side value. The ingest limits are env vars
 * on the API (`INGEST_MAX_BATCH`, `MAX_EVENT_BYTES`, `INGEST_BODY_LIMIT`), so
 * they are deploy-time configurable there and overridable here rather than
 * being treated as constants the SDK can enforce as truth.
 */
final class Constants
{
    public const SDK_NAME = 'topstats-php';
    public const SDK_VERSION = '0.1.0';
    public const USER_AGENT = self::SDK_NAME . '/' . self::SDK_VERSION;

    public const DEFAULT_HOST = 'https://topstats.gg';
    public const HOST_ENVIRONMENT_VARIABLE = 'TOPSTATS_HOST';

    public const EVENTS_PATH = '/v1/events';
    public const FLAGS_EVALUATE_PATH = '/v1/flags/evaluate';

    public const MAX_EVENT_NAME_LENGTH = 128;
    public const MAX_PROPERTY_KEY_LENGTH = 128;
    public const MAX_SOURCE_LENGTH = 128;
    public const MAX_ACTOR_LENGTH = 256;
    public const MAX_ACTOR_LABEL_LENGTH = 256;

    public const MAX_EVALUATE_KEY_LENGTH = 200;
    public const MAX_FLAG_KEYS_PER_REQUEST = 200;

    public const DEFAULT_MAX_BATCH_SIZE = 500;
    public const DEFAULT_MAX_EVENT_BYTES = 65536;
    public const DEFAULT_MAX_BODY_BYTES = 2097152;

    public const DEFAULT_FLUSH_AT = 20;
    public const DEFAULT_MAX_QUEUE_SIZE = 10000;
    public const DEFAULT_TIMEOUT_SECONDS = 10.0;
    public const DEFAULT_MAX_RETRIES = 3;

    public const INITIAL_RETRY_DELAY_SECONDS = 0.5;
    public const MAX_RETRY_DELAY_SECONDS = 30.0;

    /** A server can ask for any delay it likes; this caps how long we will honour. */
    public const MAX_RETRY_AFTER_SECONDS = 60.0;

    /** Enough of an error body to be useful in a log line, not enough to flood one. */
    public const MAX_ERROR_MESSAGE_LENGTH = 500;

    /** `{"events":[]}` around the joined events; commas are counted separately. */
    public const EVENTS_BODY_OVERHEAD_BYTES = 13;

    /**
     * PHP escapes unicode and slashes by default, which JavaScript's
     * `JSON.stringify` does not. The API measures the per-event byte cap on its
     * own re-serialisation of the parsed event, so encoding the way it does
     * keeps the local byte accounting honest.
     */
    public const JSON_ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private function __construct()
    {
    }
}
