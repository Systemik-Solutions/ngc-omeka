<?php

namespace App\Health;

/**
 * One outcome from one check.
 *
 * A check may return several of these: "pages" produces one per URL. The message is a single line;
 * anything longer belongs in the detail lines, which renderers indent underneath it.
 */
class Result
{
    /**
     * @param string $checkId The id of the check that produced this, e.g. "core.migrations".
     * @param Status $status The outcome.
     * @param string $message A single line describing the outcome.
     * @param string[] $detail Supporting lines, rendered indented under the message.
     * @param int|null $durationMs Elapsed milliseconds, for results that measured something.
     */
    public function __construct(
        public readonly string $checkId,
        public readonly Status $status,
        public readonly string $message,
        public readonly array $detail = [],
        public readonly ?int $durationMs = null,
    ) {
    }

    public static function pass(string $checkId, string $message, array $detail = [], ?int $durationMs = null): self
    {
        return new self($checkId, Status::PASS, $message, $detail, $durationMs);
    }

    public static function warn(string $checkId, string $message, array $detail = [], ?int $durationMs = null): self
    {
        return new self($checkId, Status::WARN, $message, $detail, $durationMs);
    }

    public static function fail(string $checkId, string $message, array $detail = [], ?int $durationMs = null): self
    {
        return new self($checkId, Status::FAIL, $message, $detail, $durationMs);
    }

    public static function info(string $checkId, string $message, array $detail = [], ?int $durationMs = null): self
    {
        return new self($checkId, Status::INFO, $message, $detail, $durationMs);
    }

    public static function skip(string $checkId, string $message, array $detail = []): self
    {
        return new self($checkId, Status::SKIP, $message, $detail);
    }
}
