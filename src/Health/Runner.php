<?php

namespace App\Health;

/**
 * Runs the registered checks and collects their results.
 *
 * Two guarantees matter here. A check whose probes are unavailable is skipped with a reason rather
 * than throwing, so an instance with no configured URL still reports everything it can. And a check
 * that throws becomes a FAIL carrying the exception message rather than aborting the run, so one
 * broken check cannot hide the other six.
 */
class Runner
{
    /** @var Check[] */
    private array $checks = [];

    /**
     * @param array<string, bool> $available Which probes are usable, keyed "db" and "http".
     */
    public function __construct(private array $available)
    {
    }

    public function add(Check $check): void
    {
        $this->checks[] = $check;
    }

    public function run(): Report
    {
        $report = new Report();

        foreach ($this->checks as $check) {
            $missing = $this->missingProbes($check);
            if ($missing !== []) {
                $report->add(Result::skip(
                    $check->id(),
                    $check->label() . ': not configured',
                    [$this->skipReason($missing)]
                ));
                continue;
            }

            try {
                $results = $check->run();
            } catch (\Throwable $e) {
                $report->add(Result::fail(
                    $check->id(),
                    $check->label() . ': the check itself failed',
                    [get_class($e) . ': ' . $e->getMessage()]
                ));
                continue;
            }

            $report->add(...$results);
        }

        return $report;
    }

    /**
     * @return string[] The probes this check needs that are not available.
     */
    private function missingProbes(Check $check): array
    {
        $missing = [];
        foreach ($check->requires() as $probe) {
            if (empty($this->available[$probe])) {
                $missing[] = $probe;
            }
        }
        return $missing;
    }

    /**
     * @param string[] $missing
     */
    private function skipReason(array $missing): string
    {
        $reasons = [
            'db' => 'no database connection details in public/config/database.ini',
            'http' => 'no base URL: set "url" in config/config.json or pass --url',
        ];
        $parts = [];
        foreach ($missing as $probe) {
            $parts[] = $reasons[$probe] ?? $probe;
        }
        return 'Skipped because ' . implode('; and ', $parts) . '.';
    }
}
