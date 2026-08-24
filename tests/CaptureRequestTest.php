<?php

declare(strict_types=1);

namespace TopStats\Analytics\Tests;

use TopStats\Analytics\Constants;
use TopStats\Analytics\Tests\Support\ClientTestCase;
use TopStats\Analytics\ValidationException;

final class CaptureRequestTest extends ClientTestCase
{
    public function testASingleEventIsSentInTheBatchShape(): void
    {
        $client = $this->makeClient();
        $client->capture('player_join');
        $client->flush();

        self::assertSame(1, $this->transport->requestCount());
        self::assertSame('https://topstats.gg/v1/events', $this->transport->requests[0]['url']);

        $body = json_decode($this->transport->requests[0]['body'], true);
        self::assertIsArray($body);
        self::assertSame(['events'], array_keys($body));
        self::assertCount(1, $body['events']);
    }

    public function testTheRequestCarriesExactlyTheExpectedHeaders(): void
    {
        $client = $this->makeClient();
        $client->capture('event');
        $client->flush();

        self::assertSame(
            [
                'Authorization' => 'Bearer ts_test_key',
                'Content-Type' => 'application/json',
                'User-Agent' => Constants::USER_AGENT,
            ],
            $this->transport->requests[0]['headers'],
        );
    }

    public function testTheEventCarriesOnlyAllowlistedWireFields(): void
    {
        $client = $this->makeClient();
        $client->capture('purchase', ['amount' => 9.99], [
            'actor' => 'user_123',
            'actorLabel' => 'Ada Lovelace',
            'source' => 'eu-west-1',
        ]);
        $client->flush();

        $event = $this->transport->eventsOfRequest(0)[0];

        self::assertSame('purchase', $event['name']);
        self::assertSame(['amount' => 9.99], $event['properties']);
        self::assertSame('user_123', $event['_actor']);
        self::assertSame('Ada Lovelace', $event['_actorLabel']);
        self::assertSame('eu-west-1', $event['_source']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $event['_timestamp'],
        );
        $actualKeys = array_keys($event);
        sort($actualKeys);
        self::assertSame(
            ['_actor', '_actorLabel', '_source', '_timestamp', 'name', 'properties'],
            $actualKeys,
        );
    }

    public function testUnsetOptionalFieldsAreOmittedNotNull(): void
    {
        $client = $this->makeClient();
        $client->capture('bare');
        $client->flush();

        $event = $this->transport->eventsOfRequest(0)[0];

        self::assertArrayNotHasKey('properties', $event);
        self::assertArrayNotHasKey('_source', $event);
        self::assertArrayNotHasKey('_actor', $event);
        self::assertArrayNotHasKey('_actorLabel', $event);
        self::assertStringNotContainsString('null', $this->transport->requests[0]['body']);
    }

    public function testEmptyPropertiesAreLeftOffThePayload(): void
    {
        $client = $this->makeClient();
        $client->capture('bare', []);
        $client->flush();

        self::assertArrayNotHasKey('properties', $this->transport->eventsOfRequest(0)[0]);
    }

    public function testPropertiesEncodeAsAJsonObjectWithTypesPreserved(): void
    {
        $client = $this->makeClient();
        $client->capture('purchase', [
            'amount' => 9.99,
            'count' => 3,
            'currency' => 'USD',
            'quoted' => '42',
            'flag' => true,
            'nothing' => null,
            'items' => ['hat', 'sword'],
        ]);
        $client->flush();

        $body = $this->transport->requests[0]['body'];

        self::assertStringContainsString('"properties":{', $body);
        self::assertStringContainsString('"amount":9.99', $body);
        self::assertStringContainsString('"count":3', $body);
        self::assertStringContainsString('"quoted":"42"', $body);
        self::assertStringContainsString('"flag":true', $body);
        self::assertStringContainsString('"nothing":null', $body);
        self::assertStringContainsString('"items":["hat","sword"]', $body);
    }

    public function testUnicodeIsSentRawSoByteAccountingMatchesTheApi(): void
    {
        $client = $this->makeClient();
        $client->capture('signup', ['name' => 'Ada Lovelacé']);
        $client->flush();

        self::assertStringContainsString('Ada Lovelacé', $this->transport->requests[0]['body']);
        self::assertStringNotContainsString('\\u00e9', $this->transport->requests[0]['body']);
    }

    public function testDefaultSourceAppliesWhenTheContextHasNone(): void
    {
        $client = $this->makeClient(['defaultSource' => 'worker-7']);
        $client->capture('event');
        $client->flush();

        self::assertSame('worker-7', $this->transport->eventsOfRequest(0)[0]['_source']);
    }

    public function testContextSourceBeatsDefaultSource(): void
    {
        $client = $this->makeClient(['defaultSource' => 'worker-7']);
        $client->capture('event', [], ['source' => 'eu-west-1']);
        $client->flush();

        self::assertSame('eu-west-1', $this->transport->eventsOfRequest(0)[0]['_source']);
    }

    public function testAnUnknownContextKeyIsReportedAndTheEventDropped(): void
    {
        $client = $this->makeClient();
        $client->capture('event', [], ['userId' => 'u1']);
        $client->flush();

        self::assertSame(0, $this->transport->requestCount());
        self::assertCount(1, $this->reportedErrors);
        self::assertInstanceOf(ValidationException::class, $this->reportedErrors[0]);
    }
}
