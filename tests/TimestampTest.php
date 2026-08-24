<?php

declare(strict_types=1);

namespace TopStats\Analytics\Tests;

use TopStats\Analytics\Tests\Support\ClientTestCase;
use TopStats\Analytics\ValidationException;

final class TimestampTest extends ClientTestCase
{
    private string $previousTimezone = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
    }

    public function testADateTimeWithAnOffsetZoneIsConvertedToUtcZForm(): void
    {
        $client = $this->makeClient();
        $client->capture('event', [], [
            'timestamp' => new \DateTimeImmutable('2026-01-01 12:34:56.789', new \DateTimeZone('+02:00')),
        ]);
        $client->flush();

        self::assertSame(
            '2026-01-01T10:34:56.789Z',
            $this->transport->eventsOfRequest(0)[0]['_timestamp'],
        );
    }

    public function testAMutableDateTimeIsAcceptedToo(): void
    {
        $client = $this->makeClient();
        $client->capture('event', [], [
            'timestamp' => new \DateTime('2026-06-15 08:00:00', new \DateTimeZone('America/New_York')),
        ]);
        $client->flush();

        self::assertSame(
            '2026-06-15T12:00:00.000Z',
            $this->transport->eventsOfRequest(0)[0]['_timestamp'],
        );
    }

    public function testAStringWithAnOffsetIsConvertedToZForm(): void
    {
        $client = $this->makeClient();
        $client->capture('event', [], ['timestamp' => '2026-01-01T12:00:00+02:00']);
        $client->flush();

        self::assertSame(
            '2026-01-01T10:00:00.000Z',
            $this->transport->eventsOfRequest(0)[0]['_timestamp'],
        );
    }

    public function testAUtcOffsetStringIsRewrittenToTheLiteralZTheApiRequires(): void
    {
        $client = $this->makeClient();
        $client->capture('event', [], ['timestamp' => '2026-01-01T12:00:00+00:00']);
        $client->flush();

        self::assertSame(
            '2026-01-01T12:00:00.000Z',
            $this->transport->eventsOfRequest(0)[0]['_timestamp'],
        );
    }

    public function testMicrosecondsAreNormalisedToMilliseconds(): void
    {
        $client = $this->makeClient();
        $client->capture('event', [], ['timestamp' => '2026-01-01T00:00:00.123456Z']);
        $client->flush();

        self::assertSame(
            '2026-01-01T00:00:00.123Z',
            $this->transport->eventsOfRequest(0)[0]['_timestamp'],
        );
    }

    public function testANaiveStringIsInterpretedInThePhpDefaultTimezone(): void
    {
        date_default_timezone_set('Europe/Berlin');

        $client = $this->makeClient();
        $client->capture('event', [], ['timestamp' => '2026-01-01 13:00:00']);
        $client->flush();

        // Berlin is UTC+1 in January.
        self::assertSame(
            '2026-01-01T12:00:00.000Z',
            $this->transport->eventsOfRequest(0)[0]['_timestamp'],
        );
    }

    public function testTheDefaultTimestampIsStampedAtCaptureTime(): void
    {
        $client = $this->makeClient();
        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $client->capture('event');
        $after = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $client->flush();

        $stamped = $this->transport->eventsOfRequest(0)[0]['_timestamp'];
        self::assertIsString($stamped);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $stamped);

        $stampedTime = new \DateTimeImmutable($stamped);
        self::assertGreaterThanOrEqual($before->getTimestamp() - 1, $stampedTime->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp() + 1, $stampedTime->getTimestamp());
    }

    public function testAnExpandedYearStringIsAValidationErrorNotARequest(): void
    {
        $client = $this->makeClient();
        $client->capture('event', [], ['timestamp' => '+275760-09-13T00:00:00.000Z']);
        $client->flush();

        self::assertSame(0, $this->transport->requestCount());
        self::assertCount(1, $this->reportedErrors);
        self::assertInstanceOf(ValidationException::class, $this->reportedErrors[0]);
    }

    public function testANegativeExpandedYearStringIsAValidationError(): void
    {
        $client = $this->makeClient();
        $client->capture('event', [], ['timestamp' => '-000001-01-01T00:00:00.000Z']);
        $client->flush();

        self::assertSame(0, $this->transport->requestCount());
        self::assertCount(1, $this->reportedErrors);
        self::assertInstanceOf(ValidationException::class, $this->reportedErrors[0]);
    }

    public function testADateBeyondYear9999IsAValidationError(): void
    {
        $farFuture = (new \DateTimeImmutable('2026-01-01T00:00:00Z'))->modify('+9000 years');

        $client = $this->makeClient();
        $client->capture('event', [], ['timestamp' => $farFuture]);
        $client->flush();

        self::assertSame(0, $this->transport->requestCount());
        self::assertCount(1, $this->reportedErrors);
        self::assertInstanceOf(ValidationException::class, $this->reportedErrors[0]);
    }

    public function testGarbageTimestampStringsAreValidationErrors(): void
    {
        $client = $this->makeClient();
        $client->capture('event', [], ['timestamp' => 'not a date']);
        $client->flush();

        self::assertSame(0, $this->transport->requestCount());
        self::assertCount(1, $this->reportedErrors);
        self::assertInstanceOf(ValidationException::class, $this->reportedErrors[0]);
    }

    public function testANonDateNonStringTimestampIsAValidationError(): void
    {
        $client = $this->makeClient();
        $client->capture('event', [], ['timestamp' => 1735689600]);
        $client->flush();

        self::assertSame(0, $this->transport->requestCount());
        self::assertCount(1, $this->reportedErrors);
        self::assertInstanceOf(ValidationException::class, $this->reportedErrors[0]);
    }
}
