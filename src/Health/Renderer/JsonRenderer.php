<?php

namespace App\Health\Renderer;

use App\Health\Report;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders a report for a machine.
 *
 * Writes raw, so that the output is valid JSON that can be piped somewhere. Symfony's formatter
 * would otherwise interpret anything resembling a tag in a message.
 */
class JsonRenderer
{
    public function render(Report $report, ?string $baseUrl, bool $strict, OutputInterface $output): void
    {
        $json = json_encode(
            $report->toArray($baseUrl, $strict),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $output->writeln($json, OutputInterface::OUTPUT_RAW);
    }
}
