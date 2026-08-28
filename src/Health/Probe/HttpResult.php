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
     * The URIs this request was redirected through, oldest first.
     *
     * Guzzle records these in X-Guzzle-Redirect-History when track_redirects is on, one header value
     * per hop; HttpProbe joins repeated header values with ", ", so they are split back apart here.
     * A URI containing a comma would split wrongly, but the only consumer compares whole paths
     * against a fixed list, so a mis-split can only fail to match - it cannot invent a match.
     *
     * An empty array means no redirect was followed, which is a fact about the request rather than
     * a judgement about it: what the destination means is the caller's business.
     *
     * @return string[]
     */
    public function redirectHistory(): array
    {
        $header = $this->header('x-guzzle-redirect-history');
        if ($header === null || trim($header) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $header)), static fn ($uri) => $uri !== ''));
    }

    /**
     * The path of the last URI this request was redirected to, or null when it was not redirected.
     *
     * Null therefore means "went straight there", not "unknown".
     */
    public function finalPath(): ?string
    {
        $history = $this->redirectHistory();
        if ($history === []) {
            return null;
        }
        $path = parse_url((string) end($history), PHP_URL_PATH);
        return is_string($path) ? $path : null;
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
