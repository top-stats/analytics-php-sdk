<?php

declare(strict_types=1);

namespace TopStats\Analytics\Tests;

use TopStats\Analytics\Tests\Support\ClientTestCase;
use TopStats\Analytics\TopStats;
use TopStats\Analytics\ValidationException;

final class ConstructorTest extends ClientTestCase
{
    public function testAnEmptyApiKeyThrows(): void
    {
        $this->expectException(ValidationException::class);

        new TopStats('');
    }

    public function testAWhitespaceOnlyApiKeyThrows(): void
    {
        $this->expectException(ValidationException::class);

        new TopStats('   ');
    }

    public function testAnUnknownOptionThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('flushInterval');

        new TopStats('ts_test_key', ['flushInterval' => 5000]);
    }

    public function testANonIntegerFlushAtThrows(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeClient(['flushAt' => '20']);
    }

    public function testAZeroFlushAtThrows(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeClient(['flushAt' => 0]);
    }

    public function testANegativeTimeoutThrows(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeClient(['timeoutSeconds' => -1.0]);
    }

    public function testANonCallableOnErrorThrows(): void
    {
        $this->expectException(ValidationException::class);

        new TopStats('ts_test_key', ['onError' => 'not_a_real_function_name']);
    }

    public function testAWrongTypedTransportThrows(): void
    {
        $this->expectException(ValidationException::class);

        new TopStats('ts_test_key', ['transport' => new \stdClass()]);
    }

    public function testANegativeMaxRetriesThrows(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeClient(['maxRetries' => -1]);
    }

    public function testZeroMaxRetriesIsAccepted(): void
    {
        $client = $this->makeClient(['maxRetries' => 0]);
        $client->capture('event');
        $client->flush();

        self::assertSame(1, $this->transport->requestCount());
    }

    public function testTheApiKeyIsTrimmedBeforeUse(): void
    {
        $client = $this->makeClient([], '  ts_test_key  ');
        $client->capture('event');
        $client->flush();

        self::assertSame(
            'Bearer ts_test_key',
            $this->transport->requests[0]['headers']['Authorization'],
        );
    }
}
