<?php

namespace App\Health\Check;

use App\Health\Check;
use App\Health\Probe\DbProbe;
use App\Health\Result;

/**
 * Checks that every core migration shipped on disk has been applied.
 *
 * A finer-grained signal than the version comparison: a migration run that died part way leaves the
 * version setting untouched but the migration table incomplete.
 */
class MigrationCheck implements Check
{
    private const ID = 'core.migrations';

    /** How many missing migrations to name before summarising the rest. */
    private const MAX_LISTED = 10;

    public function __construct(private string $rootDir, private DbProbe $dbProbe)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Core migrations';
    }

    public function requires(): array
    {
        return ['db'];
    }

    public function run(): array
    {
        $onDisk = $this->migrationsOnDisk();

        if ($onDisk === []) {
            return [Result::fail(self::ID, 'Core migrations: no migration files found on disk', [
                'Expected PHP files in public/application/data/migrations.',
            ])];
        }

        $applied = $this->dbProbe->migrationVersions();

        $missing = array_values(array_diff($onDisk, $applied));
        $unknown = array_values(array_diff($applied, $onDisk));

        $results = [];

        if ($missing !== []) {
            sort($missing);
            $listed = array_slice($missing, 0, self::MAX_LISTED);
            $detail = $listed;
            if (count($missing) > self::MAX_LISTED) {
                $detail[] = sprintf('...and %d more.', count($missing) - self::MAX_LISTED);
            }
            $detail[] = 'Run "php console update:db" to apply them.';
            $results[] = Result::fail(
                self::ID,
                sprintf('Core migrations: %d of %d applied, %d missing', count($onDisk) - count($missing), count($onDisk), count($missing)),
                $detail
            );
        } else {
            $results[] = Result::pass(
                self::ID,
                sprintf('Core migrations: %d of %d applied', count($onDisk), count($onDisk))
            );
        }

        if ($unknown !== []) {
            sort($unknown);
            $results[] = Result::warn(
                self::ID,
                sprintf('Core migrations: %d applied migrations have no file on disk', count($unknown)),
                array_merge(
                    array_slice($unknown, 0, self::MAX_LISTED),
                    ['This normally means the core was downgraded.']
                )
            );
        }

        return $results;
    }

    /**
     * @return string[] The numeric version prefix of each migration file, e.g. "20240103030617".
     */
    private function migrationsOnDisk(): array
    {
        $dir = $this->rootDir . '/public/application/data/migrations';
        if (!is_dir($dir)) {
            return [];
        }

        $versions = [];
        foreach ((array) glob($dir . '/*.php') as $file) {
            if (preg_match('/^(\d+)_/', basename((string) $file), $matches)) {
                $versions[] = $matches[1];
            }
        }
        return $versions;
    }
}
