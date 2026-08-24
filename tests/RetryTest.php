<?php

declare(strict_types=1);

namespace TopStats\Analytics\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use TopStats\Analytics\ApiException;
use TopStats\Analytics\Tests\Support\ClientTestCase;

final class RetryTest extends ClientTestCase
{
    public function testA429IsRetriedHonouringRetryAfterSeconds(): void
    {
        $this->transport->planResponse(429, '{"message":"rate limited"}', ['retry-after' => '2']);
        $this->transport->planResponse(202, '{"accepted":1}');

        $client = $this->makeClient();
        $client->capture('event');
        $client->flush();

        self::assertSame(2, $this->transport->requestCount());
        self::assertSame([2.0], $this->recordedDelays);
        self::assertCount(0, $this->reportedErrors);
    }

    public function testRetryAfterIsCappedAtSixtySeconds(): void
    {
        $this->transport->planResponse(429, '', ['retry-after' => '120']);
        $this->transport->planResponse(202, '{"accepted":1}');

        $client = $this->makeClient();
        $client->capture('event');
        $client->flush();

        self::assertSame([60.0], $this->recordedDelays);
    }

    public function testAnHttpDateRetryAfterIsHonoured(): void
    {
        $this->transport->planResponse(429, '', [
            'retry-after' => gmdate('D, d M Y H:i:s \G\M\T', time() + 30),
        ]);
        $this->transport->planResponse(202, '{"accepted":1}');

        $client = $this->makeClient();
        $client->capture('event');
        $client->flush();

        self::assertCount(1, $this->recordedDelays);
        self::assertGreaterThanOrEqual(25.0, $this->recordedDelays[0]);
        self::assertLessThanOrEqual(60.0, $this->recordedDelays[0]);
    }

    public function testBackoffWithoutRetryAfterIsExponentialWithJitter(): void
    {
        $this->transport->planResponse(500, 'oops');
        $this->transport->planResponse(502, 'oops');
        $this->transport->planResponse(503, 'oops');
        $this->transport->planResponse(202, '{"accepted":1}');

        $client = $this->makeClient();
        $client->capture('event');
        $client->flush();

        self::assertSame(4, $this->transport->requestCount());
        self::assertCount(3, $this->recordedDelays);

        // Half of each window is fixed and half is random.
        self::assertGreaterThanOrEqual(0.25, $this->recordedDelays[0]);
        self::assertLessThanOrEqual(0.5, $this->recordedDelays[0]);
        self::assertGreaterThanOrEqual(0.5, $this->recordedDelays[1]);
        self::assertLessThanOrEqual(1.0, $this->recordedDelays[1]);
        self::assertGreaterThanOrEqual(1.0, $this->recordedDelays[2]);
        self::assertLessThanOrEqual(2.0, $this->recordedDelays[2]);
    }

    public function testANetworkFailureIsRetried(): void
    {
        $this->transport->planNetworkFailure();
        $this->transport->planResponse(202, '{"accepted":1}');

        $client = $this->makeClient();
        $client->capture('event');
        $client->flush();

        self::assertSame(2, $this->transport->requestCount());
        self::assertCount(0, $this->reportedErrors);
    }

    /**
     * @return list<array{0: int}>
     */
    public static function permanentStatuses(): array
    {
        return [[400], [401], [402], [413], [415]];
    }

    #[DataProvider('permanentStatuses')]
    public function testPermanentStatusesAreNeverRetried(int $status): void
    {
        $this->transport->planResponse($status, '{"message":"permanent"}');

        $client = $this->makeClient();
        $client->capture('event');
        $client->flush();

        self::assertSame(1, $this->transport->requestCount());
        self::assertSame([], $this->recordedDelays);
        self::assertCount(1, $this->reportedErrors);

        $error = $this->reportedErrors[0];
        self::assertInstanceOf(ApiException::class, $error);
        self::assertSame($status, $error->status);
        self::assertFalse($error->isRetryable);
    }

    public function testRetriesStopOnceMaxRetriesIsSpent(): void
    {
        $this->transport->planResponse(503, 'down');
        $this->transport->planResponse(503, 'down');
        $this->transport->planResponse(503, 'down');

        $client = $this->makeClient(['maxRetries' => 2]);
        $client->capture('event');
        $client->flush();

        self::assertSame(3, $this->transport->requestCount());
        self::assertCount(2, $this->recordedDelays);
        self::assertCount(1, $this->reportedErrors);

        $error = $this->reportedErrors[0];
        self::assertInstanceOf(ApiException::class, $error);
        self::assertSame(503, $error->status);
        self::assertTrue($error->isRetryable);
    }

    public function testAFailedBatchIsNotRequeued(): void
    {
        $this->transport->planResponse(500, 'down');

        $client = $this->makeClient(['maxRetries' => 0]);
        $client->capture('event');
        $client->flush();

        self::assertSame(1, $this->transport->requestCount());
        self::assertCount(1, $this->reportedErrors);

        // Nothing left to send: the failed batch was dropped, not requeued.
        $client->flush();
        self::assertSame(1, $this->transport->requestCount());
    }

    public function testTheResponseMessageIsTakenFromTheErrorBody(): void
    {
        $this->transport->planResponse(402, '{"statusCode":402,"error":"Payment Required","message":"free plan cap reached"}');

        $client = $this->makeClient();
        $client->capture('event');
        $client->flush();

        $error = $this->reportedErrors[0];
        self::assertInstanceOf(ApiException::class, $error);
        self::assertSame('free plan cap reached', $error->responseMessage);
    }

    public function testALongErrorBodyIsTruncated(): void
    {
        $this->transport->planResponse(400, str_repeat('x', 2000));

        $client = $this->makeClient();
        $client->capture('event');
        $client->flush();

        $error = $this->reportedErrors[0];
        self::assertInstanceOf(ApiException::class, $error);
        self::assertSame(503, strlen($error->responseMessage));
        self::assertStringEndsWith('...', $error->responseMessage);
    }

    public function testNoReportedErrorEverMentionsTheApiKey(): void
    {
        $this->transport->planResponse(401, '{"message":"unauthorized"}');
        $this->transport->planNetworkFailure('connection refused');

        $client = $this->makeClient(['maxRetries' => 0]);
        $client->capture('event');
        $client->flush();
        $client->capture('event');
        $client->flush();

        self::assertCount(2, $this->reportedErrors);
        $this->assertNoErrorMentionsTheApiKey();
    }
}
