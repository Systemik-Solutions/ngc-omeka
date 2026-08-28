<?php

namespace App\Health\Probe;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Issues the health checker's HTTP requests.
 *
 * Requests go out one at a time. The worst case is roughly twenty-five of them, concurrency would
 * add real complexity for a second or two, and the response times this records are only meaningful
 * when the requests are not competing with each other.
 *
 * Guzzle is configured with http_errors disabled so that 4xx and 5xx arrive as responses to be
 * judged rather than as exceptions, leaving exceptions to mean an actual transport failure.
 */
class HttpProbe
{
    private Client $client;

    private string $baseUrl;

    public function __construct(string $baseUrl, int $timeoutSeconds, bool $insecure, string $userAgent)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->client = new Client([
            'http_errors' => false,
            'allow_redirects' => ['max' => 5, 'strict' => false, 'referer' => false, 'track_redirects' => false],
            'timeout' => $timeoutSeconds,
            'connect_timeout' => $timeoutSeconds,
            'verify' => !$insecure,
            'headers' => ['User-Agent' => $userAgent],
        ]);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function get(string $path): HttpResult
    {
        return $this->request('GET', $path, true);
    }

    public function head(string $path): HttpResult
    {
        return $this->request('HEAD', $path, false);
    }

    private function request(string $method, string $path, bool $keepBody): HttpResult
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $start = microtime(true);

        try {
            $response = $this->client->request($method, $url);
        } catch (GuzzleException $e) {
            return new HttpResult(
                $path,
                null,
                (int) round((microtime(true) - $start) * 1000),
                [],
                null,
                $e->getMessage()
            );
        }

        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        return new HttpResult(
            $path,
            $response->getStatusCode(),
            $elapsedMs,
            $headers,
            $keepBody ? (string) $response->getBody() : null
        );
    }
}
