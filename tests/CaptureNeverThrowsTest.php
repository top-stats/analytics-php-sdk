<?php

declare(strict_types=1);

namespace TopStats\Analytics\Tests;

use TopStats\Analytics\Tests\Support\ClientTestCase;
use TopStats\Analytics\TopStats;
use TopStats\Analytics\ValidationException;

final class CaptureNeverThrowsTest extends ClientTestCase
{
    public function testAnEmptyNameIsReportedNotThrown(): void
    {
        $client = $this->makeClient();
        $client->capture('');

        self::assertSame(['validation'], $this->reportedErrorCodes());
    }

    public function testAnOverlongNameIsReportedNotThrown(): void
    {
        $client = $this->makeClient();
        $client->capture(str_repeat('a', 129));

        self::assertSame(['validation'], $this->reportedErrorCodes());
    }

    public function testNameLengthIsMeasuredInUtf16UnitsLikeTheApi(): void
    {
        $client = $this->makeClient();

        // One emoji is two UTF-16 code units, so 64 fit and 65 do not.
        $client->capture(str_repeat("\u{1F600}", 64));
        self::assertCount(0, $this->reportedErrors);

        $client->capture(str_repeat("\u{1F600}", 65));
        self::assertSame(['validation'], $this->reportedErrorCodes());
    }

    public function testListPropertiesAreReportedNotThrown(): void
    {
        $client = $this->makeClient();
        $client->capture('event', ['a', 'b']);

        self::assertSame(['validation'], $this->reportedErrorCodes());
        $client->flush();
        self::assertSame(0, $this->transport->requestCount());
    }

    public function testAnEmptyPropertyKeyIsReported(): void
    {
        $client = $this->makeClient();
        $client->capture('event', ['' => 1]);

        self::assertSame(['validation'], $this->reportedErrorCodes());
    }

    public function testAnOverlongPropertyKeyIsReported(): void
    {
        $client = $this->makeClient();
        $client->capture('event', [str_repeat('k', 129) => 1]);

        self::assertSame(['validation'], $this->reportedErrorCodes());
    }

    public function testOverlongReservedFieldsAreReported(): void
    {
        $client = $this->makeClient();
        $client->capture('event', [], ['source' => str_repeat('s', 129)]);
        $client->capture('event', [], ['actor' => str_repeat('a', 257)]);
        $client->capture('event', [], ['actorLabel' => str_repeat('l', 257)]);

        self::assertSame(['validation', 'validation', 'validation'], $this->reportedErrorCodes());
    }

    public function testReservedFieldsAtTheirExactLimitAreAccepted(): void
    {
        $client = $this->makeClient();
        $client->capture(str_repeat('n', 128), [str_repeat('k', 128) => 1], [
            'source' => str_repeat('s', 128),
            'actor' => str_repeat('a', 256),
            'actorLabel' => str_repeat('l', 256),
        ]);
        $client->flush();

        self::assertCount(0, $this->reportedErrors);
        self::assertSame(1, $this->transport->requestCount());
    }

    public function testANonStringActorIsReported(): void
    {
        $client = $this->makeClient();
        $client->capture('event', [], ['actor' => 42]);

        self::assertSame(['validation'], $this->reportedErrorCodes());
    }

    public function testAnUnserialisablePropertyValueIsReported(): void
    {
        $client = $this->makeClient();
        $client->capture('event', ['broken' => "\xB1\x31"]);

        self::assertSame(['validation'], $this->reportedErrorCodes());
        $client->flush();
        self::assertSame(0, $this->transport->requestCount());
    }

    public function testATransportThrowingAnArbitraryExceptionCannotEscapeCapture(): void
    {
        $this->transport->planFailure(new \RuntimeException('boom'));

        $client = $this->makeClient(['flushAt' => 1]);
        $client->capture('event');

        self::assertSame(1, $this->transport->requestCount());
        self::assertCount(1, $this->reportedErrors);
        self::assertSame('boom', $this->reportedErrors[0]->getMessage());
    }

    public function testAThrowingOnErrorHandlerIsSwallowed(): void
    {
        $client = new TopStats('ts_test_key', [
            'transport' => $this->transport,
            'onError' => static function (): void {
                throw new \LogicException('bad handler');
            },
        ]);

        $client->capture('');

        // Reaching this line is the assertion: nothing escaped capture.
        self::assertSame(0, $this->transport->requestCount());
    }

    public function testCaptureAfterShutdownIsReportedNotThrown(): void
    {
        $client = $this->makeClient();
        $client->shutdown();
        $client->capture('late_event');

        self::assertSame(['client_shut_down'], $this->reportedErrorCodes());
    }

    public function testValidationErrorsCarryTheOffendingField(): void
    {
        $client = $this->makeClient();
        $client->capture('event', [], ['actor' => str_repeat('a', 257)]);

        $error = $this->reportedErrors[0];
        self::assertInstanceOf(ValidationException::class, $error);
        self::assertSame('_actor', $error->field);
    }
}
