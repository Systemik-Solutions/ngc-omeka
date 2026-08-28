<?php

namespace App\Health;

/**
 * One question about the instance's health.
 *
 * Implementations receive their probes through the constructor and hold no state between runs.
 * They must not decide whether they can run - that is requires() and the Runner's job - so that an
 * unconfigured probe produces a visible SKIP rather than an exception or a silent pass.
 */
interface Check
{
    /**
     * A stable machine-readable id, e.g. "core.migrations". Appears in the JSON output.
     */
    public function id(): string;

    /**
     * A short human label for the console output, e.g. "Core migrations applied".
     */
    public function label(): string;

    /**
     * The probes this check needs.
     *
     * @return string[] Any of "db" and "http". An empty array means the check needs neither and
     *   always runs.
     */
    public function requires(): array;

    /**
     * @return Result[] One or more results. Never empty: a check with nothing to say returns a
     *   single PASS or INFO explaining that.
     */
    public function run(): array;
}
