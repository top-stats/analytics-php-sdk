<?php

declare(strict_types=1);

namespace TopStats\Analytics\Tests\Support;

use TopStats\Analytics\NetworkException;
use TopStats\Analytics\Transport\TransportInterface;
use TopStats\Analytics\Transport\TransportResponse;

/**
 * Records every attempt and answers from a planned script. With nothing
 * planned, every request succeeds with a 202.
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<array{url: string, body: string, headers: array<string, string>, timeoutSeconds: float}> */
    public array $requests = [];

    /** @var list<TransportResponse|\Throwable> */
    private array $plannedResults = [];

    /**
     * @param array<string, string> $headers header names lowercased
     */
    public function planResponse(int $status, string $body = '', array $headers = []): void
    {
        $this->plannedResults[] = new TransportResponse($status, $body, $headers);
    }

    public function planFailure(\Throwable $failure): void
    {
        $this->plannedResults[] = $failure;
    }

    public function planNetworkFailure(string $message = 'connection refused'): void
    {
        $this->planFailure(new NetworkException($message));
    }

    public function post(string $url, string $body, array $headers, float $timeoutSeconds): TransportResponse
    {
        $this->requests[] = [
            'url' => $url,
            'body' => $body,
            'headers' => $headers,
            'timeoutSeconds' => $timeoutSeconds,
        ];

        if ($this->plannedResults === []) {
            return new TransportResponse(202, '{"accepted":1}');
        }

        $result = array_shift($this->plannedResults);

        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    /**
     * The decoded `events` array of one recorded ingest request.
     *
     * @return list<array<string, mixed>>
     */
    public function eventsOfRequest(int $index): array
    {
        $decoded = json_decode($this->requests[$index]['body'], true);
        \assert(is_array($decoded) && is_array($decoded['events']));

        return $decoded['events'];
    }
}
