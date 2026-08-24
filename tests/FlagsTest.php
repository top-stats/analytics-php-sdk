<?php

declare(strict_types=1);

namespace TopStats\Analytics\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use TopStats\Analytics\ApiException;
use TopStats\Analytics\FlagResult;
use TopStats\Analytics\Tests\Support\ClientTestCase;
use TopStats\Analytics\TopStatsException;
use TopStats\Analytics\ValidationException;

final class FlagsTest extends ClientTestCase
{
    private function planFlags(string $json): void
    {
        $this->transport->planResponse(200, $json);
    }

    public function testAnEmptyEvaluationStillSendsAnEmptyJsonObject(): void
    {
        $this->planFlags('{"flags":{}}');

        $client = $this->makeClient();
        $results = $client->evaluate([]);

        self::assertSame([], $results);
        self::assertSame(1, $this->transport->requestCount());
        self::assertSame('https://topstats.gg/v1/flags/evaluate', $this->transport->requests[0]['url']);
        self::assertSame('{}', $this->transport->requests[0]['body']);
        self::assertSame(
            'Bearer ts_test_key',
            $this->transport->requests[0]['headers']['Authorization'],
        );
    }

    public function testTheFullInputIsPassedThroughTrimmedButOtherwiseVerbatim(): void
    {
        $this->planFlags('{"flags":{}}');

        $client = $this->makeClient();
        $client->evaluate([
            'actorKey' => '  user_123  ',
            'groupKey' => 'guild_9',
            'keys' => ['  new-checkout ', 'weird key ✓/émoji 🎉'],
            'logExposure' => false,
        ]);

        $body = json_decode($this->transport->requests[0]['body'], true);

        self::assertSame([
            'actorKey' => 'user_123',
            'groupKey' => 'guild_9',
            'keys' => ['new-checkout', 'weird key ✓/émoji 🎉'],
            'logExposure' => false,
        ], $body);
    }

    public function testFlagKeysAreNotRestrictedToAnyCharset(): void
    {
        $this->planFlags('{"flags":{"weird key":{"value":true,"variant":"true","reason":"boolean"}}}');

        $client = $this->makeClient();
        $results = $client->evaluate(['keys' => ['weird key']]);

        self::assertTrue($results['weird key']->value);
    }

    public function testTheResponseIsParsedIntoFlagResults(): void
    {
        $this->planFlags(
            '{"flags":{'
            . '"new-checkout":{"value":true,"variant":"true","reason":"rollout"},'
            . '"beta-search":{"value":false,"variant":"false","reason":"disabled"},'
            . '"missing":{"value":false,"variant":"false","reason":"not_found"}'
            . '}}',
        );

        $client = $this->makeClient();
        $results = $client->evaluate(['actorKey' => 'user_123']);

        self::assertCount(3, $results);
        self::assertInstanceOf(FlagResult::class, $results['new-checkout']);
        self::assertTrue($results['new-checkout']->value);
        self::assertSame('true', $results['new-checkout']->variant);
        self::assertSame('rollout', $results['new-checkout']->reason);
        self::assertSame('disabled', $results['beta-search']->reason);
        self::assertSame('not_found', $results['missing']->reason);
    }

    public function testMoreThanTwoHundredKeysIsAValidationError(): void
    {
        $client = $this->makeClient();

        $this->expectException(ValidationException::class);

        $client->evaluate(['keys' => array_fill(0, 201, 'flag')]);
    }

    public function testABlankActorKeyIsAValidationError(): void
    {
        $client = $this->makeClient();

        $this->expectException(ValidationException::class);

        $client->evaluate(['actorKey' => '   ']);
    }

    public function testAnUnknownInputKeyIsAValidationError(): void
    {
        $client = $this->makeClient();

        $this->expectException(ValidationException::class);

        $client->evaluate(['actor' => 'user_123']);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function malformedResponses(): array
    {
        return [
            ['[]'],
            ['{"nope":true}'],
            ['{"flags":{"a":"on"}}'],
            ['{"flags":{"a":{"value":"true","variant":"true","reason":"boolean"}}}'],
            ['{"flags":{"a":{"value":true,"variant":"yes","reason":"boolean"}}}'],
            ['{"flags":{"a":{"value":true,"variant":"true","reason":"mystery"}}}'],
        ];
    }

    #[DataProvider('malformedResponses')]
    public function testAMalformedResponseThrowsInsteadOfGuessing(string $responseBody): void
    {
        $this->planFlags($responseBody);

        $client = $this->makeClient();

        $this->expectException(TopStatsException::class);

        $client->evaluate(['keys' => ['a']]);
    }

    public function testEvaluateRetriesA429ThenSucceeds(): void
    {
        $this->transport->planResponse(429, '', ['retry-after' => '1']);
        $this->planFlags('{"flags":{}}');

        $client = $this->makeClient();
        $results = $client->evaluate([]);

        self::assertSame([], $results);
        self::assertSame(2, $this->transport->requestCount());
        self::assertSame([1.0], $this->recordedDelays);
    }

    public function testEvaluateThrowsOnA401WithoutRetrying(): void
    {
        $this->transport->planResponse(401, '{"message":"unauthorized"}');

        $client = $this->makeClient();

        try {
            $client->evaluate([]);
            self::fail('evaluate should have thrown');
        } catch (ApiException $caught) {
            self::assertSame(401, $caught->status);
            self::assertStringNotContainsString('ts_test_key', $caught->getMessage());
        }

        self::assertSame(1, $this->transport->requestCount());
    }

    public function testIsEnabledReturnsTrueForAnEnabledFlag(): void
    {
        $this->planFlags('{"flags":{"new-checkout":{"value":true,"variant":"true","reason":"rollout"}}}');

        $client = $this->makeClient();

        self::assertTrue($client->isEnabled('new-checkout', ['actorKey' => 'user_123']));

        $body = json_decode($this->transport->requests[0]['body'], true);
        self::assertIsArray($body);
        self::assertSame(['new-checkout'], $body['keys']);
        self::assertSame('user_123', $body['actorKey']);
    }

    public function testIsEnabledReturnsFalseForADisabledFlag(): void
    {
        $this->planFlags('{"flags":{"new-checkout":{"value":false,"variant":"false","reason":"disabled"}}}');

        $client = $this->makeClient();

        self::assertFalse($client->isEnabled('new-checkout'));
    }

    public function testIsEnabledReturnsFalseWhenTheKeyIsMissingFromTheResponse(): void
    {
        $this->planFlags('{"flags":{}}');

        $client = $this->makeClient();

        self::assertFalse($client->isEnabled('new-checkout'));
        self::assertCount(0, $this->reportedErrors);
    }

    public function testIsEnabledReturnsFalseOnAnyFailureAndReportsIt(): void
    {
        $this->transport->planNetworkFailure();

        $client = $this->makeClient(['maxRetries' => 0]);

        self::assertFalse($client->isEnabled('new-checkout'));
        self::assertSame(['network_error'], $this->reportedErrorCodes());
    }

    public function testIsEnabledReturnsFalseOnABlankKeyWithoutAnyRequest(): void
    {
        $client = $this->makeClient();

        self::assertFalse($client->isEnabled('   '));
        self::assertSame(0, $this->transport->requestCount());
        self::assertSame(['validation'], $this->reportedErrorCodes());
    }

    public function testIsEnabledTrimsTheKeyBeforeAskingAndReading(): void
    {
        $this->planFlags('{"flags":{"new-checkout":{"value":true,"variant":"true","reason":"boolean"}}}');

        $client = $this->makeClient();

        self::assertTrue($client->isEnabled('  new-checkout  '));
    }
}
