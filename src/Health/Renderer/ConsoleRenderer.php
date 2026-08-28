<?php

namespace App\Health\Renderer;

use App\Health\Report;
use App\Health\Result;
use App\Health\Status;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders a report for a person.
 *
 * Results are grouped by the first segment of the check id, so "core.version" and "core.migrations"
 * appear together under one heading without the checks having to know about presentation.
 */
class ConsoleRenderer
{
    private const COLOURS = [
        'PASS' => 'green',
        'WARN' => 'yellow',
        'FAIL' => 'red',
        'INFO' => 'cyan',
        'SKIP' => 'gray',
    ];

    /**
     * Headings for check id prefixes that do not read well capitalised by rule.
     */
    private const GROUP_LABELS = [
        'api' => 'API',
    ];

    public function render(Report $report, OutputInterface $output, bool $strict = false): void
    {
        $groups = [];
        foreach ($report->all() as $result) {
            $groups[$this->groupOf($result)][] = $result;
        }

        foreach ($groups as $group => $results) {
            $output->writeln('<options=bold>' . $group . '</>');
            foreach ($results as $result) {
                $output->writeln('  ' . $this->tag($result) . ' ' . $this->line($result));
                foreach ($result->detail as $detail) {
                    $output->writeln('         ' . $detail);
                }
            }
            $output->writeln('');
        }

        $this->renderSummary($report, $output, $strict);
    }

    private function groupOf(Result $result): string
    {
        $segment = explode('.', $result->checkId)[0];
        return self::GROUP_LABELS[$segment] ?? ucfirst($segment);
    }

    private function tag(Result $result): string
    {
        $colour = self::COLOURS[$result->status->value] ?? 'default';
        return sprintf('<fg=%s>[%s]</>', $colour, $result->status->value);
    }

    private function line(Result $result): string
    {
        if ($result->durationMs === null) {
            return $result->message;
        }
        return $result->message . ' (' . $result->durationMs . 'ms)';
    }

    private function renderSummary(Report $report, OutputInterface $output, bool $strict): void
    {
        $tally = $report->tally();
        $parts = [];
        foreach (['PASS' => 'passed', 'WARN' => 'warned', 'FAIL' => 'failed'] as $status => $word) {
            $parts[] = $tally[$status] . ' ' . $word;
        }
        foreach (['INFO' => 'informational', 'SKIP' => 'skipped'] as $status => $word) {
            if ($tally[$status] > 0) {
                $parts[] = $tally[$status] . ' ' . $word;
            }
        }

        $output->writeln(implode(', ', $parts) . '.');

        if ($tally[Status::FAIL->value] > 0) {
            $output->writeln('<fg=red;options=bold>Instance unhealthy.</>');
        } elseif ($tally[Status::WARN->value] > 0 && $strict) {
            $output->writeln('<fg=red;options=bold>Instance unhealthy: --strict treats warnings as failures.</>');
        } elseif ($tally[Status::WARN->value] > 0) {
            $output->writeln('<fg=yellow>Instance healthy, with warnings.</>');
        } else {
            $output->writeln('<fg=green>Instance healthy.</>');
        }
    }
}
