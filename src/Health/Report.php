<?php

namespace App\Health;

/**
 * Everything a health check run produced.
 *
 * The single structure both renderers read, and the only thing that decides the exit code.
 */
class Report
{
    /** @var Result[] */
    private array $results = [];

    public function add(Result ...$results): void
    {
        foreach ($results as $result) {
            $this->results[] = $result;
        }
    }

    /**
     * @return Result[]
     */
    public function all(): array
    {
        return $this->results;
    }

    /**
     * Count the results by status.
     *
     * @return array<string, int> Keyed by status value, with every status present even at zero, so
     *   renderers do not have to handle missing keys.
     */
    public function tally(): array
    {
        $tally = [];
        foreach (Status::cases() as $status) {
            $tally[$status->value] = 0;
        }
        foreach ($this->results as $result) {
            $tally[$result->status->value]++;
        }
        return $tally;
    }

    public function hasFailures(): bool
    {
        return $this->tally()[Status::FAIL->value] > 0;
    }

    /**
     * The process exit code.
     *
     * INFO and SKIP never contribute, not even under strict: one reports a fact with no judgement,
     * the other reports that something was not configured.
     */
    public function exitCode(bool $strict): int
    {
        $tally = $this->tally();
        if ($tally[Status::FAIL->value] > 0) {
            return 1;
        }
        if ($strict && $tally[Status::WARN->value] > 0) {
            return 1;
        }
        return 0;
    }

    /**
     * The machine-readable document emitted by --json.
     */
    public function toArray(?string $baseUrl, bool $strict): array
    {
        $tally = $this->tally();
        return [
            'generated_at' => date('c'),
            'base_url' => $baseUrl,
            'summary' => [
                'pass' => $tally[Status::PASS->value],
                'warn' => $tally[Status::WARN->value],
                'fail' => $tally[Status::FAIL->value],
                'info' => $tally[Status::INFO->value],
                'skip' => $tally[Status::SKIP->value],
            ],
            'exit_code' => $this->exitCode($strict),
            'results' => array_map(static fn (Result $r) => [
                'check' => $r->checkId,
                'status' => $r->status->value,
                'message' => $r->message,
                'detail' => $r->detail,
                'duration_ms' => $r->durationMs,
            ], $this->results),
        ];
    }
}
