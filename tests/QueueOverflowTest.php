<?php

declare(strict_types=1);

namespace TopStats\Analytics\Tests;

use TopStats\Analytics\QueueOverflowException;
use TopStats\Analytics\Tests\Support\ClientTestCase;

final class QueueOverflowTest extends ClientTestCase
{
    public function testTheOldestEventsAreDroppedAndReportedWhenTheQueueIsFull(): void
    {
        $client = $this->makeClient(['maxQueueSize' => 3]);

        foreach (['first', 'second', 'third', 'fourth', 'fifth'] as $name) {
            $client->capture($name);
        }

        self::assertSame(['queue_overflow', 'queue_overflow'], $this->reportedErrorCodes());

        foreach ($this->reportedErrors as $error) {
            self::assertInstanceOf(QueueOverflowException::class, $error);
            self::assertSame(1, $error->droppedCount);
            self::assertSame(3, $error->maxQueueSize);
        }

        $client->flush();

        self::assertSame(1, $this->transport->requestCount());
        self::assertSame(
            ['third', 'fourth', 'fifth'],
            array_column($this->transport->eventsOfRequest(0), 'name'),
        );
    }

    public function testAnEventArrivingDuringOverflowIsKeptItselfNeverBlocked(): void
    {
        $client = $this->makeClient(['maxQueueSize' => 1]);
        $client->capture('first');
        $client->capture('second');
        $client->flush();

        self::assertSame(
            ['second'],
            array_column($this->transport->eventsOfRequest(0), 'name'),
        );
    }
}
