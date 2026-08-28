<?php

namespace App\Health;

use App\Distribution\Inspector;
use App\Health\Probe\DbProbe;

/**
 * Instance state at one moment, for the before-and-after comparison inside a single update run.
 *
 * Never written to disk. The standalone health check is deliberately stateless; this exists only
 * because within one update process the two snapshots cost nothing and catch the one thing absolute
 * thresholds cannot - an update that silently destroyed data.
 *
 * Captured through DbProbe and Inspector, both of which read raw PDO and the filesystem, so taking
 * the "before" snapshot inside the update's lockBootstrap() window is safe.
 */
class Snapshot
{
    /**
     * @param array<string, array{public: int, private: int}> $resourceCounts
     */
    public function __construct(
        public readonly array $resourceCounts,
        public readonly ?string $coreVersion,
    ) {
    }

    public static function capture(DbProbe $dbProbe, Inspector $inspector): self
    {
        return new self($dbProbe->resourceCounts(), $inspector->getCoreVersion());
    }

    /**
     * Compare this snapshot against an earlier one.
     *
     * A count that dropped is a failure. A count that rose or held is reported without judgement:
     * content can legitimately be added between the two snapshots, and an update is not the only
     * thing touching the instance.
     *
     * @return Result[]
     */
    public function diff(Snapshot $before): array
    {
        $id = 'update.diff';
        $results = [];

        if ($before->coreVersion !== $this->coreVersion) {
            $results[] = Result::info($id, sprintf(
                'Core version: %s => %s',
                $before->coreVersion ?? 'unknown',
                $this->coreVersion ?? 'unknown'
            ));
        } else {
            $results[] = Result::info($id, sprintf('Core version: unchanged at %s', $this->coreVersion ?? 'unknown'));
        }

        $types = array_unique(array_merge(array_keys($before->resourceCounts), array_keys($this->resourceCounts)));
        sort($types);

        $dropped = [];
        $changed = [];

        foreach ($types as $type) {
            $label = $this->shortType($type);
            foreach (['public', 'private'] as $visibility) {
                $was = $before->resourceCounts[$type][$visibility] ?? 0;
                $now = $this->resourceCounts[$type][$visibility] ?? 0;
                if ($now < $was) {
                    $dropped[] = sprintf('%s (%s): %d => %d', $label, $visibility, $was, $now);
                } elseif ($now > $was) {
                    $changed[] = sprintf('%s (%s): %d => %d', $label, $visibility, $was, $now);
                }
            }
        }

        if ($dropped !== []) {
            $results[] = Result::fail(
                $id,
                sprintf('Resource counts: %d count(s) dropped during the update', count($dropped)),
                array_merge($dropped, ['Content is missing that was present before the update.'])
            );
        } elseif ($changed !== []) {
            $results[] = Result::info(
                $id,
                sprintf('Resource counts: %d count(s) rose, none dropped', count($changed)),
                $changed
            );
        } else {
            $results[] = Result::info($id, 'Resource counts: unchanged');
        }

        return $results;
    }

    /**
     * Turn "Omeka\Entity\Item" into "Item" for display.
     */
    private function shortType(string $type): string
    {
        $parts = explode('\\', $type);
        return end($parts) ?: $type;
    }
}
