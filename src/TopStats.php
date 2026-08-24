<?php

declare(strict_types=1);

namespace TopStats\Analytics;

use TopStats\Analytics\Transport\StreamTransport;
use TopStats\Analytics\Transport\TransportInterface;
use TopStats\Analytics\Transport\TransportResponse;

/**
 * The TopStats Analytics client. `capture` buffers in memory and never throws;
 * the buffer is sent when it reaches `flushAt`, on an explicit `flush()`, and
 * on `shutdown()` or object destruction. PHP has no background threads, so
 * there is no timer: unlike the Node SDK, sending happens on the calling
 * thread when one of those triggers fires.
 */
final class TopStats
{
    private const OPTION_KEYS = [
        'host',
        'flushAt',
        'maxRetries',
        'timeoutSeconds',
        'onError',
        'defaultSource',
        'maxQueueSize',
        'maxBatchSize',
        'maxEventBytes',
        'maxBodyBytes',
        'transport',
        'sleepFunction',
    ];

    private readonly string $apiKey;
    private readonly string $host;
    private readonly int $flushAt;
    private readonly int $maxRetries;
    private readonly float $timeoutSeconds;
    private readonly \Closure $onErrorHandler;
    private readonly ?string $defaultSource;
    private readonly int $maxBatchSize;
    private readonly int $maxEventBytes;
    private readonly int $maxBodyBytes;
    private readonly int $maxQueueSize;
    private readonly TransportInterface $transport;
    private readonly \Closure $sleepFunction;
    private readonly EventQueue $queue;

    private bool $isShutDown = false;
    private bool $isFlushing = false;

    /**
     * @param array<string, mixed> $options
     *
     * @throws ValidationException when the key is blank or an option is invalid;
     *                             the constructor is the only throwing path
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if (trim($apiKey) === '') {
            throw new ValidationException(
                'An API key is required, for example ts_live_... or ts_test_...',
                'apiKey',
            );
        }

        self::rejectUnknownOptions($options);

        $this->apiKey = trim($apiKey);
        $this->host = self::resolveHost(self::stringOption($options, 'host'));
        $this->flushAt = self::positiveIntOption($options, 'flushAt', Constants::DEFAULT_FLUSH_AT);
        $this->maxRetries = self::nonNegativeIntOption($options, 'maxRetries', Constants::DEFAULT_MAX_RETRIES);
        $this->timeoutSeconds = self::positiveFloatOption($options, 'timeoutSeconds', Constants::DEFAULT_TIMEOUT_SECONDS);
        $this->onErrorHandler = self::callableOption($options, 'onError') ?? self::defaultErrorHandler();
        $this->defaultSource = self::stringOption($options, 'defaultSource');
        $this->maxBatchSize = self::positiveIntOption($options, 'maxBatchSize', Constants::DEFAULT_MAX_BATCH_SIZE);
        $this->maxEventBytes = self::positiveIntOption($options, 'maxEventBytes', Constants::DEFAULT_MAX_EVENT_BYTES);
        $this->maxBodyBytes = self::positiveIntOption($options, 'maxBodyBytes', Constants::DEFAULT_MAX_BODY_BYTES);
        $this->transport = self::transportOption($options) ?? new StreamTransport();
        $this->sleepFunction = self::callableOption($options, 'sleepFunction') ?? self::defaultSleepFunction();
        $this->maxQueueSize = self::positiveIntOption($options, 'maxQueueSize', Constants::DEFAULT_MAX_QUEUE_SIZE);
        $this->queue = new EventQueue($this->maxQueueSize);
    }

    /**
     * Buffers one event. Never throws: a bad value, a full queue and a failed
     * send all arrive at `onError` instead. When the buffer reaches `flushAt`
     * the send happens on this call, since PHP has no background thread to
     * hand it to.
     *
     * @param array<string, mixed> $properties
     * @param array{actor?: string, actorLabel?: string, source?: string, timestamp?: \DateTimeInterface|string} $context
     */
    public function capture(string $name, array $properties = [], array $context = []): void
    {
        try {
            if ($this->isShutDown) {
                $this->reportError(new TopStatsException(
                    sprintf('The client is shut down, so "%s" was dropped', $name),
                    'client_shut_down',
                ));

                return;
            }

            $encoded = EventEncoder::encode($name, $properties, $context, $this->defaultSource);
            $this->enqueue($encoded);
        } catch (\Throwable $caught) {
            $this->reportError($caught);
        }
    }

    /**
     * Sends everything buffered and returns when the queue is drained. A batch
     * that fails after its retries is reported to `onError` and not requeued:
     * ingest has no idempotency key, so replaying a batch the API may have
     * accepted would duplicate events.
     */
    public function flush(): void
    {
        // A capture made inside an onError handler could otherwise recurse
        // back into the drain that is already running.
        if ($this->isFlushing) {
            return;
        }

        $this->isFlushing = true;

        try {
            // Only what was queued when the drain started, so a caller under
            // sustained capture pressure still gets their flush back.
            $remaining = $this->queue->size();

            while ($remaining > 0) {
                $batch = $this->queue->takeBatch($this->maxBatchSize, $this->maxBodyBytes);

                if ($batch === []) {
                    return;
                }

                $remaining -= count($batch);
                $this->sendBatch($batch);
            }
        } finally {
            $this->isFlushing = false;
        }
    }

    /** Flushes and refuses further events. Safe to call twice. */
    public function shutdown(): void
    {
        if ($this->isShutDown) {
            return;
        }

        $this->isShutDown = true;
        $this->flush();
    }

    /** Best-effort flush at object destruction; never throws during shutdown. */
    public function __destruct()
    {
        try {
            $this->shutdown();
        } catch (\Throwable) {
            // Nothing left to report to: the process is tearing the object down.
        }
    }

    /**
     * Evaluates feature flags for one actor. Unlike `capture` this is a real
     * request the caller made for an answer, so it throws on failure.
     *
     * @param array{actorKey?: string, groupKey?: string, keys?: list<string>, logExposure?: bool} $input
     *
     * @return array<string, FlagResult> keyed by flag key; every requested key is present
     */
    public function evaluate(array $input = []): array
    {
        $body = self::buildEvaluateBody($input);
        $payload = $this->sendRequest(Constants::FLAGS_EVALUATE_PATH, $body);

        return FlagResponseParser::parse($payload);
    }

    /**
     * False whenever the flag is off, unknown, or the request failed. Failures
     * are reported to `onError`, never thrown.
     *
     * @param array{actorKey?: string, groupKey?: string, logExposure?: bool} $input
     */
    public function isEnabled(string $key, array $input = []): bool
    {
        try {
            $flagKey = Validation::validatedFlagKey($key);
            $input['keys'] = [$flagKey];
            $results = $this->evaluate($input);

            return isset($results[$flagKey]) && $results[$flagKey]->value;
        } catch (\Throwable $caught) {
            $this->reportError($caught);

            return false;
        }
    }

    private function enqueue(EncodedEvent $encoded): void
    {
        if ($encoded->byteLength > $this->maxEventBytes) {
            // Sending it would spend a whole request on a guaranteed 413.
            $this->reportError(new EventTooLargeException(
                $encoded->name,
                $encoded->byteLength,
                $this->maxEventBytes,
            ));

            return;
        }

        $droppedCount = $this->queue->enqueue($encoded);

        if ($droppedCount > 0) {
            $this->reportError(new QueueOverflowException($droppedCount, $this->maxQueueSize));
        }

        if ($this->queue->size() >= $this->flushAt) {
            $this->flush();
        }
    }

    /**
     * @param non-empty-list<EncodedEvent> $batch
     */
    private function sendBatch(array $batch): void
    {
        $eventJsons = [];

        foreach ($batch as $event) {
            $eventJsons[] = $event->json;
        }

        // Concatenated rather than re-serialised, so the byte budget stays exact.
        $body = '{"events":[' . implode(',', $eventJsons) . ']}';

        try {
            $this->sendRequest(Constants::EVENTS_PATH, $body);
        } catch (\Throwable $caught) {
            $this->reportError($caught);
        }
    }

    /**
     * Returns the decoded response body, or throws once the failure is
     * permanent or the retries are spent. Retries cover 429, 5xx and anything
     * that never produced a response; 400, 401, 402, 413 and 415 will not fix
     * themselves, so they are thrown on the first attempt.
     */
    private function sendRequest(string $path, string $body): mixed
    {
        $url = $this->host . $path;

        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->attemptRequest($url, $body);
            } catch (TopStatsException $caught) {
                if (!self::isRetryableFailure($caught) || $attempt >= $this->maxRetries) {
                    throw $caught;
                }

                ($this->sleepFunction)(
                    RetryDelay::secondsBeforeRetry($attempt, self::retryAfterSecondsOf($caught)),
                );
            }
        }
    }

    private function attemptRequest(string $url, string $body): mixed
    {
        $response = $this->transport->post($url, $body, $this->requestHeaders(), $this->timeoutSeconds);

        if ($response->status < 200 || $response->status >= 300) {
            throw ApiException::fromResponse($response);
        }

        return self::parseResponseBody($response);
    }

    /**
     * @return array<string, string>
     */
    private function requestHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'User-Agent' => Constants::USER_AGENT,
        ];
    }

    private function reportError(\Throwable $error): void
    {
        try {
            ($this->onErrorHandler)($error);
        } catch (\Throwable) {
            // A throwing error handler is the caller's problem, not an outage.
        }
    }

    private static function isRetryableFailure(TopStatsException $error): bool
    {
        if ($error instanceof ApiException) {
            return $error->isRetryable;
        }

        return $error instanceof NetworkException;
    }

    private static function retryAfterSecondsOf(TopStatsException $error): ?float
    {
        return $error instanceof ApiException ? $error->retryAfterSeconds : null;
    }

    private static function parseResponseBody(TransportResponse $response): mixed
    {
        if (trim($response->body) === '') {
            return null;
        }

        try {
            return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $caught) {
            throw new TopStatsException('The API returned a body that is not JSON', 'api_error', $caught);
        }
    }

    /**
     * @param array<array-key, mixed> $input
     */
    private static function buildEvaluateBody(array $input): string
    {
        $wireBody = [];

        foreach (array_keys($input) as $key) {
            if (!in_array($key, ['actorKey', 'groupKey', 'keys', 'logExposure'], true)) {
                throw new ValidationException(
                    sprintf(
                        'Unknown evaluate key "%s"; the input accepts actorKey, groupKey, keys and logExposure',
                        (string) $key,
                    ),
                    'input',
                );
            }
        }

        if (array_key_exists('actorKey', $input)) {
            $wireBody['actorKey'] = Validation::validatedEvaluateKey($input['actorKey'], 'actorKey');
        }

        if (array_key_exists('groupKey', $input)) {
            $wireBody['groupKey'] = Validation::validatedEvaluateKey($input['groupKey'], 'groupKey');
        }

        if (array_key_exists('keys', $input)) {
            if (!is_array($input['keys'])) {
                throw new ValidationException('keys must be an array', 'keys');
            }

            $wireBody['keys'] = Validation::validatedFlagKeys($input['keys']);
        }

        if (array_key_exists('logExposure', $input)) {
            if (!is_bool($input['logExposure'])) {
                throw new ValidationException('logExposure must be a boolean', 'logExposure');
            }

            $wireBody['logExposure'] = $input['logExposure'];
        }

        // Cast to object so an empty body encodes as `{}`, never `[]`: the
        // endpoint is a strict object and rejects a missing or array body.
        $json = json_encode((object) $wireBody, Constants::JSON_ENCODE_FLAGS);

        if ($json === false) {
            throw new ValidationException(
                sprintf('The evaluate input could not be serialised as JSON: %s', json_last_error_msg()),
                'input',
            );
        }

        return $json;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function rejectUnknownOptions(array $options): void
    {
        foreach (array_keys($options) as $key) {
            if (!in_array($key, self::OPTION_KEYS, true)) {
                throw new ValidationException(
                    sprintf(
                        'Unknown option "%s"; supported options are %s',
                        $key,
                        implode(', ', self::OPTION_KEYS),
                    ),
                    'options',
                );
            }
        }
    }

    /**
     * A blank `TOPSTATS_HOST`, which is what an unset variable looks like in
     * most container runtimes, must fall through to the default rather than
     * building the relative URL `/v1/events` and failing every send.
     */
    private static function resolveHost(?string $configured): string
    {
        $environmentValue = getenv(Constants::HOST_ENVIRONMENT_VARIABLE);

        foreach ([$configured, $environmentValue === false ? null : $environmentValue] as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $trimmed = trim($candidate);

            if ($trimmed !== '') {
                return rtrim($trimmed, '/');
            }
        }

        return Constants::DEFAULT_HOST;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function stringOption(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new ValidationException(sprintf('The %s option must be a string', $name), $name);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function positiveIntOption(array $options, string $name, int $default): int
    {
        $value = $options[$name] ?? null;

        if ($value === null) {
            return $default;
        }

        if (!is_int($value) || $value < 1) {
            throw new ValidationException(sprintf('The %s option must be a positive integer', $name), $name);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function nonNegativeIntOption(array $options, string $name, int $default): int
    {
        $value = $options[$name] ?? null;

        if ($value === null) {
            return $default;
        }

        if (!is_int($value) || $value < 0) {
            throw new ValidationException(sprintf('The %s option must be zero or a positive integer', $name), $name);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function positiveFloatOption(array $options, string $name, float $default): float
    {
        $value = $options[$name] ?? null;

        if ($value === null) {
            return $default;
        }

        if ((!is_int($value) && !is_float($value)) || $value <= 0) {
            throw new ValidationException(sprintf('The %s option must be a positive number', $name), $name);
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function callableOption(array $options, string $name): ?\Closure
    {
        $value = $options[$name] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_callable($value)) {
            throw new ValidationException(sprintf('The %s option must be a callable', $name), $name);
        }

        return \Closure::fromCallable($value);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function transportOption(array $options): ?TransportInterface
    {
        $value = $options['transport'] ?? null;

        if ($value === null) {
            return null;
        }

        if (!$value instanceof TransportInterface) {
            throw new ValidationException('The transport option must implement TransportInterface', 'transport');
        }

        return $value;
    }

    private static function defaultErrorHandler(): \Closure
    {
        return static function (\Throwable $error): void {
            error_log(sprintf('[topstats] %s: %s', get_class($error), $error->getMessage()));
        };
    }

    private static function defaultSleepFunction(): \Closure
    {
        return static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }
}
