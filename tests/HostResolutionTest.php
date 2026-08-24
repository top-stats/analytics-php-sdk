<?php

declare(strict_types=1);

namespace TopStats\Analytics\Tests;

use TopStats\Analytics\Tests\Support\ClientTestCase;

final class HostResolutionTest extends ClientTestCase
{
    protected function tearDown(): void
    {
        putenv('TOPSTATS_HOST');
    }

    public function testTheDefaultHostIsThePublicApi(): void
    {
        putenv('TOPSTATS_HOST');

        $client = $this->makeClient();
        $client->capture('event');
        $client->flush();

        self::assertSame('https://topstats.gg/v1/events', $this->transport->requests[0]['url']);
    }

    public function testTheHostOptionWins(): void
    {
        putenv('TOPSTATS_HOST=https://env.example.test');

        $client = $this->makeClient(['host' => 'https://option.example.test']);
        $client->capture('event');
        $client->flush();

        self::assertSame('https://option.example.test/v1/events', $this->transport->requests[0]['url']);
    }

    public function testTheEnvironmentVariableIsUsedWhenNoOptionIsGiven(): void
    {
        putenv('TOPSTATS_HOST=https://env.example.test');

        $client = $this->makeClient();
        $client->capture('event');
        $client->flush();

        self::assertSame('https://env.example.test/v1/events', $this->transport->requests[0]['url']);
    }

    public function testABlankEnvironmentVariableIsTreatedAsUnset(): void
    {
        // An unset variable often arrives as an empty string in container
        // runtimes; treating it as a host would produce a relative URL.
        putenv('TOPSTATS_HOST=   ');

        $client = $this->makeClient();
        $client->capture('event');
        $client->flush();

        self::assertSame('https://topstats.gg/v1/events', $this->transport->requests[0]['url']);
    }

    public function testABlankHostOptionFallsThroughToTheEnvironment(): void
    {
        putenv('TOPSTATS_HOST=https://env.example.test');

        $client = $this->makeClient(['host' => '   ']);
        $client->capture('event');
        $client->flush();

        self::assertSame('https://env.example.test/v1/events', $this->transport->requests[0]['url']);
    }

    public function testTrailingSlashesAreStripped(): void
    {
        $client = $this->makeClient(['host' => 'https://option.example.test///']);
        $client->capture('event');
        $client->flush();

        self::assertSame('https://option.example.test/v1/events', $this->transport->requests[0]['url']);
    }
}
