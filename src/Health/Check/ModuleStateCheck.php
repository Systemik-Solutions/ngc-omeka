<?php

namespace App\Health\Check;

use App\Distribution\Inspector;
use App\Distribution\Manifest;
use App\Health\Check;
use App\Health\Probe\DbProbe;
use App\Health\Result;

/**
 * Compares every module on disk with its row in the module table.
 *
 * Only a version gap is a failure. Whether a module is active is a project decision - a site that
 * does not need a feature may legitimately turn it off - and which modules a project runs beyond
 * the distribution is worth reporting but is not a defect either. Both are INFO.
 */
class ModuleStateCheck implements Check
{
    private const ID = 'modules.state';

    public function __construct(
        private string $rootDir,
        private Inspector $inspector,
        private DbProbe $dbProbe,
        private Manifest $manifest,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Module state';
    }

    public function requires(): array
    {
        return ['db'];
    }

    public function run(): array
    {
        $onDisk = $this->modulesOnDisk();
        $rows = $this->dbProbe->moduleRows();
        $inManifest = $this->manifest->resolveModuleIds(null);

        $results = [];
        $behind = [];
        $ahead = [];
        $notInstalled = [];
        $inactive = [];
        $extra = [];

        foreach ($onDisk as $id) {
            $diskVersion = $this->inspector->getModuleVersion($id);
            if (!isset($rows[$id])) {
                if (in_array($id, $inManifest, true)) {
                    $notInstalled[] = $id;
                }
                continue;
            }

            $dbVersion = $rows[$id]['version'];
            if ($diskVersion !== null) {
                $comparison = version_compare($dbVersion, $diskVersion);
                if ($comparison < 0) {
                    $behind[] = sprintf('%s: database %s, disk %s', $id, $dbVersion, $diskVersion);
                } elseif ($comparison > 0) {
                    $ahead[] = sprintf('%s: database %s, disk %s', $id, $dbVersion, $diskVersion);
                }
            }

            if (!$rows[$id]['active']) {
                $inactive[] = $id;
            }
            if (!in_array($id, $inManifest, true)) {
                $extra[] = $id . ' ' . ($diskVersion ?? 'unknown version');
            }
        }

        $orphaned = array_values(array_diff(array_keys($rows), $onDisk));

        if ($behind !== []) {
            $results[] = Result::fail(
                self::ID,
                sprintf('Module state: %d module(s) have not been upgraded', count($behind)),
                array_merge($behind, ['The database is behind the code. Run "php console update:db".'])
            );
        }

        if ($ahead !== []) {
            $results[] = Result::fail(
                self::ID,
                sprintf('Module state: %d module(s) are older on disk than in the database', count($ahead)),
                array_merge($ahead, ['The code was downgraded under a migrated schema. Restore the newer module code.'])
            );
        }

        if ($behind === [] && $ahead === []) {
            $results[] = Result::pass(
                self::ID,
                sprintf('Module state: %d module(s) on disk, versions match the database', count($onDisk))
            );
        }

        if ($notInstalled !== []) {
            sort($notInstalled);
            $results[] = Result::warn(
                self::ID,
                sprintf('Module state: %d distribution module(s) are on disk but not installed', count($notInstalled)),
                array_merge($notInstalled, ['The database step has not run for these. Run "php console update:db".'])
            );
        }

        if ($orphaned !== []) {
            sort($orphaned);
            $results[] = Result::warn(
                self::ID,
                sprintf('Module state: %d module(s) are recorded in the database but missing from disk', count($orphaned)),
                $orphaned
            );
        }

        sort($inactive);
        $results[] = Result::info(
            self::ID,
            $inactive === []
                ? 'Inactive modules: none'
                : sprintf('Inactive modules: %d', count($inactive)),
            $inactive
        );

        sort($extra);
        $results[] = Result::info(
            self::ID,
            $extra === []
                ? 'Modules not in the distribution: none'
                : sprintf('Modules not in the distribution: %d', count($extra)),
            $extra
        );

        return $results;
    }

    /**
     * @return string[] The directory name of every module present under public/modules.
     */
    private function modulesOnDisk(): array
    {
        $dir = $this->rootDir . '/public/modules';
        if (!is_dir($dir)) {
            return [];
        }

        $ids = [];
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($dir . '/' . $entry)) {
                continue;
            }
            if (!is_file($dir . '/' . $entry . '/config/module.ini')) {
                continue;
            }
            $ids[] = $entry;
        }
        sort($ids);
        return $ids;
    }
}
