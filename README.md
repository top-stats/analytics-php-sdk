# topstats/analytics

Official TopStats Analytics client for PHP. It buffers events in memory, sends
them to `https://topstats.gg` in batches, and evaluates feature flags. Zero
runtime dependencies: it uses PHP's stream wrapper for HTTP, so it needs
nothing beyond core PHP 8.1 or newer.

- `capture` never throws and never fails your request path. Problems go to
  your `onError` handler instead.
- Batches are split at the server's event count and byte limits before
  sending, and retried with backoff where a retry can help.

## How flushing works in PHP (read this first)

PHP has no background threads, so unlike the Node SDK there is no flush timer.
The client sends buffered events at exactly three moments:

1. When the buffer reaches `flushAt` events (default 20). The send happens on
   the `capture` call that crossed the threshold.
2. When you call `flush()`.
3. On `shutdown()`, or as a best effort when the client is destroyed.

This is the honest PHP story, not a gap: a typical PHP process handles one
request and exits, so a timer would have nothing to run on. In a long-running
worker (a queue consumer, a daemon, Swoole or ReactPHP), call `flush()`
yourself at a sensible interval. In a normal request lifecycle, either let the
destructor flush or call `shutdown()` when your response is done.

## Install

The package is not yet published to Packagist. Until it is, install it from
the GitHub repository:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/top-stats/analytics-php-sdk" }
    ],
    "require": {
        "topstats/analytics": "dev-master"
    }
}
```

```bash
composer update topstats/analytics
```

## Quick start

```php
use TopStats\Analytics\TopStats;

$topstats = new TopStats(getenv('TOPSTATS_KEY'));

$topstats->capture('player_join', ['map' => 'desert', 'playtime' => 42], [
    'actor' => 'user_123',
    'actorLabel' => 'Ada Lovelace',
]);

// Before the process finishes, send whatever is still buffered.
$topstats->shutdown();
```

### Your API key decides where events land

The key alone selects the workspace and the environment, so you never put
either in a payload. A `ts_live_` key writes to production and a `ts_test_`
key writes to a non-production environment. To send events somewhere else,
swap the key, not the code.

Keep the key on your server. It grants write access to your workspace, so it
must never ship in a browser bundle, a mobile app, or a game client.

## Configuration

```php
$topstats = new TopStats(getenv('TOPSTATS_KEY'), [
    'flushAt' => 50,
    'defaultSource' => 'eu-west-1',
    'onError' => fn (\Throwable $error) => $logger->warning('topstats: ' . $error->getMessage()),
]);
```

The constructor takes the API key and an options array. It throws a
`ValidationException` if the key is missing or blank, or if an option is
unknown or mistyped. That is the only throwing path outside `evaluate`.

| Option | Type | Default | What it does |
| --- | --- | --- | --- |
| `host` | `string` | `TOPSTATS_HOST`, then `https://topstats.gg` | Base URL of the API. Trailing slashes are stripped, and a blank value falls through to the next default. |
| `flushAt` | `int` | `20` | Buffer length that triggers a send. |
| `maxRetries` | `int` | `3` | Retries after the first attempt, for retryable failures only. |
| `timeoutSeconds` | `int` or `float` | `10.0` | Per-request timeout in seconds. |
| `onError` | `callable(\Throwable): void` | logs via `error_log` | Called for every dropped event, failed batch, and validation problem. |
| `defaultSource` | `string` | none | `_source` applied to events that do not carry their own. Max 128 characters. |
| `maxQueueSize` | `int` | `10000` | Events held in memory before the oldest are dropped. |
| `maxBatchSize` | `int` | `500` | Events per request. Matches the server cap. |
| `maxEventBytes` | `int` | `65536` | Bytes per event before it is dropped. Matches the server cap. |
| `maxBodyBytes` | `int` | `2097152` | Bytes per request body. Matches the server cap. |
| `transport` | `TransportInterface` | PHP streams | The HTTP layer. Inject a fake in tests. |
| `sleepFunction` | `callable(float): void` | `usleep` | How retry backoff waits. Inject a recorder in tests. |

The three `max*Bytes`/`maxBatchSize` caps mirror server-side values that are
deploy-time configurable on the API, which is why you can override them here.
Leave them alone unless you are pointing `host` at an API you run yourself.

## Capturing events

```php
$topstats->capture($name, $properties, $context);
```

`capture` validates the event, serialises it once, and puts it on the buffer.
It never throws. Only `name` is required: 1 to 128 characters, and it is what
charts group by, so keep it consistent across events.

### Properties

Properties are your own key/value pairs. Keys are 1 to 128 characters. Values
are any JSON, and the API routes each one by its JSON type:

| You send | Stored as | What you can do with it |
| --- | --- | --- |
| A finite number (`42`, `9.99`) | Number property | Sum, average, min, max, histogram |
| A non-empty array (`['hat', 'sword']`) | List property | Break down per value, filter |
| Anything else (string, bool, object, `null`) | String property | Group, count distinct, filter |

Two things to watch:

- A quoted number is a string. Send `9.99`, not `'9.99'`, or it cannot be
  summed or averaged.
- An empty array is dropped server-side, so a property sent as `[]` will not
  exist on the stored event.

An empty properties array is left off the payload entirely. Passing a list
(`['a', 'b']` rather than `['key' => 'value']`) is a validation error, because
it would serialise as a JSON array where the API requires an object.

### Context

The third argument carries the reserved platform fields, named without their
underscore prefix:

| Field | Wire field | Type | Limit | What it is |
| --- | --- | --- | --- | --- |
| `actor` | `_actor` | `string` | 256 chars | Who the event belongs to. Powers the Actors view and retention. |
| `actorLabel` | `_actorLabel` | `string` | 256 chars | A readable name for that actor, shown instead of the raw id. |
| `source` | `_source` | `string` | 128 chars | Where the event came from, such as a region or a shard. Falls back to `defaultSource`. |
| `timestamp` | `_timestamp` | `DateTimeInterface` or `string` | ISO 8601 | When it happened. Defaults to the moment you called `capture`. |

```php
$topstats->capture('purchase', ['amount' => 9.99, 'currency' => 'USD'], [
    'actor' => 'user_123',
    'actorLabel' => 'Ada Lovelace',
    'source' => 'eu-west-1',
    'timestamp' => new DateTimeImmutable('now'),
]);
```

The SDK stamps `_timestamp` at capture time rather than leaving it to the
server, so a buffered batch does not land on a single instant. Any
`DateTimeInterface` or string you pass is converted to UTC and formatted with
milliseconds and a literal `Z` suffix, which is the only form the API accepts:
a value ending in `+00:00` would be a 400, so let the SDK do the conversion. A
date the API cannot express, such as a year outside 0000 to 9999, is reported
as a validation error rather than sent. A string without a timezone is
interpreted in PHP's default timezone, then converted.

## Batching and flushing

Every captured event goes into a bounded FIFO buffer and is sent in a batch,
always in the `{"events": [...]}` shape. A single flush drains the whole
buffer, splitting it into requests that respect both `maxBatchSize` (500) and
`maxBodyBytes` (2 MiB). Events are measured in bytes at capture time, so
batches are split on the size the API will measure.

- A single event over `maxEventBytes` (64 KiB) is dropped at capture time and
  reported, because sending it would spend a whole request on a guaranteed 413.
- If the buffer reaches `maxQueueSize`, the oldest events are dropped to make
  room and you get a `QueueOverflowException` on `onError`. Dropping the
  oldest keeps live dashboards current during a backlog.
- A batch that fails after its retries is not requeued. Ingest has no
  idempotency key, so replaying a batch the API may have already accepted
  would duplicate events. The failure is reported to `onError` instead.

## Error handling

`capture` and `isEnabled` never throw. Everything they would have thrown
arrives at `onError`, which by default writes a line via `error_log`. Throwing
from inside your handler is caught and ignored. Error messages never contain
your API key.

| Class | `errorCode` | Extra fields | When you get it |
| --- | --- | --- | --- |
| `ValidationException` | `validation` | `field` | A value the API would have rejected with a 400, caught before it was sent. |
| `EventTooLargeException` | `event_too_large` | `eventName`, `byteLength`, `maxEventBytes` | One event is over `maxEventBytes`, so it was dropped. |
| `QueueOverflowException` | `queue_overflow` | `droppedCount`, `maxQueueSize` | The buffer hit `maxQueueSize` and the oldest events were dropped. |
| `ApiException` | `api_error` | `status`, `responseMessage`, `isRetryable`, `retryAfterSeconds` | The API answered with a non-2xx status. |
| `NetworkException` | `network_error` | none | The request never produced a response: DNS, connection, or timeout. |
| `TopStatsException` | `client_shut_down` | none | You captured an event after `shutdown()`. |

### Status codes

| Status | Cause | What to do |
| --- | --- | --- |
| 400 | The payload failed validation. | Fix the payload. The SDK catches most of these before they are sent. |
| 401 | The API key is missing, malformed, unknown, or revoked. | Check the key is current. |
| 402 | A free workspace has hit its monthly event cap. | Upgrade, or wait for the next month. |
| 413 | Too many events, an oversized event, or an oversized body. | The SDK splits batches for you; lower the caps if you run your own API. |
| 429 | Over the rate limit. | Nothing. The SDK backs off and retries. Raise `flushAt` to send fewer, larger requests. |

### Retries

Retries cover 429, every 5xx, and anything that never produced a response.
400, 401, 402, 413, and 415 will not fix themselves, so they fail on the
first attempt. Backoff starts at 500 ms and doubles up to 30 seconds; half of
each window is fixed and half is random, because the rate limit is keyed on
the client IP and lockstep retries from one egress address would all collide
again. A `Retry-After` header is honoured instead of the backoff, up to 60
seconds.

## Feature flags

`evaluate` asks the API for one actor's flags. Unlike `capture`, it is a real
request you asked for an answer to, so it throws on failure.

```php
$flags = $topstats->evaluate([
    'actorKey' => 'user_123',
    'keys' => ['new-checkout', 'beta-search'],
]);

if (isset($flags['new-checkout']) && $flags['new-checkout']->value) {
    renderNewCheckout();
}
```

Each result is a `FlagResult` with a `value` (bool), a `variant` (`'true'` or
`'false'`) and a `reason` (`rollout`, `boolean`, `disabled`, `no_actor` or
`not_found`). Every key you asked for comes back, including keys that do not
exist, which arrive with `reason` `not_found`. Anything other than `boolean`
or `rollout` means the flag did not really get to decide, so treat a false
`value` as "keep the old behaviour".

| Input key | Type | Limit | What it does |
| --- | --- | --- | --- |
| `actorKey` | `string` | 1 to 200 characters after trimming | The bucket key for flags that bucket by actor. |
| `groupKey` | `string` | 1 to 200 characters after trimming | The bucket key for flags that bucket by group. |
| `keys` | `string[]` | at most 200 | Which flags to evaluate. Leave it out to evaluate every flag in the environment. |
| `logExposure` | `bool` | | Exposure logging is on by default. Pass `false` to ask without recording. |

Flag keys are sent as you wrote them, minus surrounding whitespace. The API
caps how many keys you send, not what characters they contain, so the SDK
does not restrict their charset either.

For a single flag, `isEnabled` is shorter. It never throws: an unknown key, a
flag that is off, and a failed request all return `false`, and the failure
goes to `onError`.

```php
if ($topstats->isEnabled('new-checkout', ['actorKey' => 'user_123'])) {
    renderNewCheckout();
}
```

## Graceful shutdown

`shutdown()` flushes what is buffered and refuses further events. It is safe
to call more than once. Any `capture` after it is reported with the
`client_shut_down` code rather than silently ignored. The destructor calls
`shutdown()` as a best effort and swallows every failure, so a dying request
cannot be taken down by telemetry, but do not rely on destructor ordering for
delivery: call `flush()` or `shutdown()` yourself at the end of the request.

```php
$topstats = new TopStats(getenv('TOPSTATS_KEY'));

// ... handle the request, capturing along the way ...

$topstats->shutdown();
```

In a long-running worker, call `flush()` between jobs and `shutdown()` when
the worker stops.

## Limits

These mirror the server. The SDK enforces the first three locally so a
request that would fail is never sent.

| Limit | Default |
| --- | --- |
| Events per batch | 500 |
| Bytes per single event | 64 KiB (65536) |
| Bytes per whole request | 2 MiB (2097152) |
| Ingest rate limit | 6000 requests per minute, per client IP |
| Flag evaluation rate limit | 6000 requests per minute, per client IP |

| Field | Limit |
| --- | --- |
| `name` | 1 to 128 characters |
| Property keys | 1 to 128 characters |
| `source` | 128 characters |
| `actor` | 256 characters |
| `actorLabel` | 256 characters |
| `actorKey`, `groupKey` | 1 to 200 characters after trimming |
| `keys` | 200 entries |

## Testing your integration

The HTTP layer sits behind `TopStats\Analytics\Transport\TransportInterface`.
Inject your own implementation through the `transport` option and no test
ever touches the network:

```php
use TopStats\Analytics\Transport\TransportInterface;
use TopStats\Analytics\Transport\TransportResponse;

final class CapturingTransport implements TransportInterface
{
    public array $requests = [];

    public function post(string $url, string $body, array $headers, float $timeoutSeconds): TransportResponse
    {
        $this->requests[] = [$url, $body];

        return new TransportResponse(202, '{"accepted":1}');
    }
}
```

## Documentation

Full documentation, including dashboards, segments, groups, and the rest of
the platform, is at
[docs.topstats.gg/docs/analytics](https://docs.topstats.gg/docs/analytics).

## License

MIT
