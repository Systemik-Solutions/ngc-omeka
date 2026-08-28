<?php

namespace App\Health\Probe;

/**
 * One HTTP request outcome.
 *
 * A transport failure - connection refused, DNS failure, timeout - has a null status code and a
 * populated error, which is a different thing from a request that reached the server and came back
 * 500.
 */
class HttpResult
{
    /**
     * @param array<string, string> $headers Response headers, keyed lowercase.
     */
    public function __construct(
        public readonly string $path,
        public readonly ?int $statusCode,
        public readonly int $elapsedMs,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly ?string $error = null,
    ) {
    }

    public function isTransportError(): bool
    {
        return $this->statusCode === null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function contentType(): ?string
    {
        return $this->header('content-type');
    }

    /**
     * The declared body size.
     *
     * Read from the header rather than measured from the body, because HEAD responses have no body
     * at all and measuring would report zero for every healthy file.
     */
    public function contentLength(): ?int
    {
        $value = $this->header('content-length');
        return $value === null ? null : (int) $value;
    }
}
