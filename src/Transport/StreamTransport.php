<?php

declare(strict_types=1);

namespace TopStats\Analytics\Transport;

use TopStats\Analytics\NetworkException;

/**
 * The default transport, built on PHP's HTTP stream wrapper so the SDK needs
 * nothing beyond core PHP: no curl extension, no HTTP client dependency.
 */
final class StreamTransport implements TransportInterface
{
    public function post(string $url, string $body, array $headers, float $timeoutSeconds): TransportResponse
    {
        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        // Without this the server may hold a 1.1 connection open and
        // file_get_contents will wait for it to close.
        $headerLines[] = 'Connection: close';

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => $timeoutSeconds,
                // A non-2xx response must be returned, not turned into a warning.
                'ignore_errors' => true,
                'follow_location' => 0,
                'protocol_version' => 1.1,
            ],
        ]);

        error_clear_last();
        $responseBody = @file_get_contents($url, false, $context);

        if ($responseBody === false) {
            // The failure message may name the URL; it never contains headers,
            // so the API key cannot leak through it.
            throw new NetworkException(self::describeFailure($url));
        }

        // $http_response_header is populated by the http wrapper in this
        // scope on every response, and the URL is always http(s), so no
        // fallback is needed (phpstan rejects a dead one).
        $rawHeaderLines = $http_response_header;
        [$status, $responseHeaders] = self::parseResponseHeaders($rawHeaderLines);

        return new TransportResponse($status, $responseBody, $responseHeaders);
    }

    private static function describeFailure(string $url): string
    {
        $lastError = error_get_last();
        $detail = $lastError !== null ? $lastError['message'] : 'unknown error';

        return sprintf('The request to %s failed: %s', $url, $detail);
    }

    /**
     * The wrapper appends one block of lines per hop. Redirects are disabled,
     * but a `100 Continue` block can still precede the real one, so the last
     * status line seen wins and resets the header map.
     *
     * @param array<string> $rawHeaderLines
     *
     * @return array{0: int, 1: array<string, string>}
     */
    private static function parseResponseHeaders(array $rawHeaderLines): array
    {
        $status = 0;
        $headers = [];

        foreach ($rawHeaderLines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $statusMatch) === 1) {
                $status = (int) $statusMatch[1];
                $headers = [];

                continue;
            }

            $separatorPosition = strpos($line, ':');

            if ($separatorPosition === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $separatorPosition)));
            $headers[$name] = trim(substr($line, $separatorPosition + 1));
        }

        return [$status, $headers];
    }
}
