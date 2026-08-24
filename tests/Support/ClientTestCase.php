<?php

declare(strict_types=1);

namespace TopStats\Analytics\Tests\Support;

use PHPUnit\Framework\TestCase;
use TopStats\Analytics\TopStats;
use TopStats\Analytics\TopStatsException;

abstract class ClientTestCase extends TestCase
{
    protected FakeTransport $transport;

    /** @var list<\Throwable> */
    protected array $reportedErrors = [];

    /** @var list<float> */
    protected array $recordedDelays = [];

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->reportedErrors = [];
        $this->recordedDelays = [];
    }

    /**
     * A client wired to the fake transport, an error collector, and a sleep
     * recorder. `flushAt` is high so tests control exactly when sends happen.
     *
     * @param array<string, mixed> $options
     */
    protected function makeClient(array $options = [], string $apiKey = 'ts_test_key'): TopStats
    {
        return new TopStats($apiKey, array_merge([
            'transport' => $this->transport,
            'onError' => function (\Throwable $error): void {
                $this->reportedErrors[] = $error;
            },
            'sleepFunction' => function (float $seconds): void {
                $this->recordedDelays[] = $seconds;
            },
            'flushAt' => 1000,
        ], $options));
    }

    /** @return list<string> */
    protected function reportedErrorCodes(): array
    {
        return array_map(
            static fn (\Throwable $error): string => $error instanceof TopStatsException
                ? $error->errorCode
                : get_class($error),
            $this->reportedErrors,
        );
    }

    protected function assertNoErrorMentionsTheApiKey(string $apiKey = 'ts_test_key'): void
    {
        foreach ($this->reportedErrors as $error) {
            self::assertStringNotContainsString($apiKey, $error->getMessage());
        }
    }
}
