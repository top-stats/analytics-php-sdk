<?php

declare(strict_types=1);

namespace TopStats\Analytics\Transport;

/** One HTTP response, however the transport obtained it. */
final class TransportResponse
{
    /**
     * @param array<string, string> $headers header names lowercased
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
