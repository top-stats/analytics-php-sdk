<?php

declare(strict_types=1);

namespace TopStats\Analytics\Tests;

use TopStats\Analytics\Tests\Support\ClientTestCase;

final class ShutdownTest extends ClientTestCase
{
    public function testShutdownFlushesWhateverIsBuffered(): void
    {
        $client = $this->makeClient();
        $client->capture('one');
        $client->capture('two');

        self::assertSame(0, $this->transport->requestCount());

        $client->shutdown();

        self::assertSame(1, $this->transport->requestCount());
        self::assertCount(2, $this->transport->eventsOfRequest(0));
    }

    public function testShutdownIsIdempotent(): void
    {
        $client = $this->makeClient();
        $client->capture('event');
        $client->shutdown();
        $client->shutdown();

        self::assertSame(1, $this->transport->requestCount());
    }

    public function testCaptureAfterShutdownSendsNothing(): void
    {
        $client = $this->makeClient();
        $client->shutdown();
        $client->capture('late_event');
        $client->flush();

        self::assertSame(0, $this->transport->requestCount());
        self::assertSame(['client_shut_down'], $this->reportedErrorCodes());
    }

    public function testDestructionFlushesAsALastResort(): void
    {
        $client = $this->makeClient();
        $client->capture('event');

        self::assertSame(0, $this->transport->requestCount());

        unset($client);

        self::assertSame(1, $this->transport->requestCount());
    }

    public function testDestructionAfterShutdownSendsNothingTwice(): void
    {
        $client = $this->makeClient();
        $client->capture('event');
        $client->shutdown();

        unset($client);

        self::assertSame(1, $this->transport->requestCount());
    }

    public function testDestructionSwallowsSendFailures(): void
    {
        $this->transport->planNetworkFailure();

        $client = $this->makeClient(['maxRetries' => 0]);
        $client->capture('event');

        unset($client);

        // Reaching this line is the assertion: the destructor did not throw.
        self::assertSame(1, $this->transport->requestCount());
        self::assertSame(['network_error'], $this->reportedErrorCodes());
    }
}
