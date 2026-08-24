<?php

declare(strict_types=1);

namespace TopStats\Analytics\Tests;

use TopStats\Analytics\Constants;
use TopStats\Analytics\EventTooLargeException;
use TopStats\Analytics\Tests\Support\ClientTestCase;

final class BatchingTest extends ClientTestCase
{
    public function testNothingIsSentBeforeFlushAtIsReached(): void
    {
        $client = $this->makeClient(['flushAt' => 3]);
        $client->capture('one');
        $client->capture('two');

        self::assertSame(0, $this->transport->requestCount());

        $client->capture('three');

        self::assertSame(1, $this->transport->requestCount());
        self::assertCount(3, $this->transport->eventsOfRequest(0));
    }

    public function testABatchSplitsAtTheDefaultFiveHundredEventCap(): void
    {
        $client = $this->makeClient();

        for ($index = 0; $index < 501; $index++) {
            $client->capture('event', [], ['timestamp' => '2026-01-01T00:00:00.000Z']);
        }

        $client->flush();

        self::assertSame(2, $this->transport->requestCount());
        self::assertCount(Constants::DEFAULT_MAX_BATCH_SIZE, $this->transport->eventsOfRequest(0));
        self::assertCount(1, $this->transport->eventsOfRequest(1));
    }

    public function testABatchSplitsAtAConfiguredEventCap(): void
    {
        $client = $this->makeClient(['maxBatchSize' => 5]);

        for ($index = 0; $index < 12; $index++) {
            $client->capture('event');
        }

        $client->flush();

        self::assertSame(3, $this->transport->requestCount());
        self::assertCount(5, $this->transport->eventsOfRequest(0));
        self::assertCount(5, $this->transport->eventsOfRequest(1));
        self::assertCount(2, $this->transport->eventsOfRequest(2));
    }

    public function testABatchSplitsBeforeTheBodyByteLimit(): void
    {
        $timestamp = '2026-01-01T00:00:00.000Z';
        $eventBytes = strlen((string) json_encode(
            ['name' => 'e1', '_timestamp' => $timestamp],
            Constants::JSON_ENCODE_FLAGS,
        ));

        // Room for exactly two events and the comma between them.
        $maxBodyBytes = Constants::EVENTS_BODY_OVERHEAD_BYTES + (2 * $eventBytes) + 1;
        $client = $this->makeClient(['maxBodyBytes' => $maxBodyBytes]);

        foreach (['e1', 'e2', 'e3', 'e4', 'e5'] as $name) {
            $client->capture($name, [], ['timestamp' => $timestamp]);
        }

        $client->flush();

        self::assertSame(3, $this->transport->requestCount());
        self::assertCount(2, $this->transport->eventsOfRequest(0));
        self::assertCount(2, $this->transport->eventsOfRequest(1));
        self::assertCount(1, $this->transport->eventsOfRequest(2));

        foreach ($this->transport->requests as $request) {
            self::assertLessThanOrEqual($maxBodyBytes, strlen($request['body']));
        }
    }

    public function testAnOversizedEventIsDroppedAndReportedNeverSent(): void
    {
        $client = $this->makeClient(['maxEventBytes' => 200]);
        $client->capture('big_event', ['blob' => str_repeat('x', 500)]);
        $client->flush();

        self::assertSame(0, $this->transport->requestCount());
        self::assertCount(1, $this->reportedErrors);

        $error = $this->reportedErrors[0];
        self::assertInstanceOf(EventTooLargeException::class, $error);
        self::assertSame('big_event', $error->eventName);
        self::assertSame(200, $error->maxEventBytes);
        self::assertGreaterThan(200, $error->byteLength);
    }

    public function testAnOversizedEventDoesNotAffectItsNeighbours(): void
    {
        $client = $this->makeClient(['maxEventBytes' => 200]);
        $client->capture('small_before');
        $client->capture('big_event', ['blob' => str_repeat('x', 500)]);
        $client->capture('small_after');
        $client->flush();

        self::assertSame(1, $this->transport->requestCount());

        $names = array_column($this->transport->eventsOfRequest(0), 'name');
        self::assertSame(['small_before', 'small_after'], $names);
    }
}
