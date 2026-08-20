<?php

namespace App\Distribution;

/**
 * Builds the plan of work an update has to do, without bootstrapping Omeka S.
 *
 * The plan is two-sided. A component can be behind on **code** (what is on disk against what the
 * manifest asks for) or behind on the **database** (what the schema was migrated to against what the
 * code will be running once the download has finished), and these move independently: a run that
 * died between the download and the migration leaves the code current and the database stranded.
 * Auditing both means re-running the same command finishes the job.
 *
 * Every read goes through Inspector, so nothing here loads an Omeka class. See that class for why
 * that matters.
 */
class Auditor
{
    private Manifest $manifest;

    private Inspector $inspector;

    public function __construct(Manifest $manifest, Inspector $inspector)
    {
        $this->manifest = $manifest;
        $this->inspector = $inspector;
    }

    /**
     * Audit the Omeka S core.
     *
     * @param bool $force Re-download the core even when it is already at the manifest version.
     * @return array With a "code" and a "db" key, each either null or a ["from" => ?string,
     *   "to" => string] pair. A "forced" flag marks a code entry that exists only because of $force.
     */
    public function auditCore(bool $force = false): array
    {
        $manifestVersion = $this->manifest->getCore()['version'];
        $diskVersion = $this->inspector->getCoreVersion();

        $code = null;
        if ($diskVersion === null || version_compare($diskVersion, $manifestVersion, '<')) {
            $code = ['from' => $diskVersion, 'to' => $manifestVersion, 'forced' => false];
        } elseif ($force) {
            $code = ['from' => $diskVersion, 'to' => $manifestVersion, 'forced' => true];
        }

        // The database is compared against the version the core will be running after this command,
        // which is the manifest version when the code is being downloaded and the version already on
        // disk otherwise.
        $targetVersion = $code && !$code['forced'] ? $manifestVersion : ($diskVersion ?? $manifestVersion);
        $dbVersion = $this->inspector->getInstalledCoreVersion();

        $db = null;
        if ($dbVersion === null || version_compare($dbVersion, $targetVersion, '<')) {
            $db = ['from' => $dbVersion, 'to' => $targetVersion];
        }

        return ['code' => $code, 'db' => $db];
    }

    /**
     * Audit a set of modules.
     *
     * @param array $ids The module IDs to audit. Already resolved against the manifest.
     * @param bool $force Re-download the modules even when they are already at the manifest version.
     * @return array Keyed by module ID, each entry shaped like auditCore()'s return value. Modules
     *   with nothing to do are omitted.
     */
    public function auditModules(array $ids, bool $force = false): array
    {
        $plan = [];
        foreach ($ids as $id) {
            $entry = $this->auditModule($id, $force);
            if ($entry['code'] || $entry['db']) {
                $plan[$id] = $entry;
            }
        }
        return $plan;
    }

    /**
     * Audit a set of themes.
     *
     * Themes have no database side: Omeka S registers them straight off the filesystem, so there is
     * nothing to install or upgrade once the files are in place.
     *
     * @param array $ids The theme IDs to audit. Already resolved against the manifest.
     * @param bool $force Re-download the themes even when they are already at the manifest version.
     * @return array Keyed by theme ID, each entry with a "code" key. Themes with nothing to do are
     *   omitted.
     */
    public function auditThemes(array $ids, bool $force = false): array
    {
        $plan = [];
        foreach ($ids as $id) {
            $manifestVersion = $this->manifest->getTheme($id)['version'];
            $diskVersion = $this->inspector->isThemeRegistered($id)
                ? $this->inspector->getThemeVersion($id)
                : null;

            if ($diskVersion === null || version_compare($diskVersion, $manifestVersion, '<')) {
                $plan[$id] = ['code' => ['from' => $diskVersion, 'to' => $manifestVersion, 'forced' => false]];
            } elseif ($force) {
                $plan[$id] = ['code' => ['from' => $diskVersion, 'to' => $manifestVersion, 'forced' => true]];
            }
        }
        return $plan;
    }

    /**
     * Check whether the legacy "Mapping" module version 1.0.0 is installed.
     *
     * The rename to "MappingExtensions" cannot be automated, so an update that would touch
     * MappingExtensions has to stop and send the user to the README.
     */
    public function hasLegacyMapping(): bool
    {
        return $this->inspector->getModuleVersion('Mapping') === '1.0.0';
    }

    /**
     * Audit a single module.
     */
    private function auditModule(string $id, bool $force): array
    {
        $manifestVersion = $this->manifest->getModule($id)['version'];
        $diskVersion = $this->inspector->getModuleVersion($id);

        $code = null;
        if ($diskVersion === null || version_compare($diskVersion, $manifestVersion, '<')) {
            $code = ['from' => $diskVersion, 'to' => $manifestVersion, 'forced' => false];
        } elseif ($force) {
            $code = ['from' => $diskVersion, 'to' => $manifestVersion, 'forced' => true];
        }

        // As for the core, the database is compared against the version the module will be running
        // after this command rather than against the manifest, so that a module deliberately left
        // behind the manifest is not reported as needing a database upgrade it cannot have.
        $targetVersion = $code && !$code['forced'] ? $manifestVersion : ($diskVersion ?? $manifestVersion);
        $dbVersion = $this->inspector->getInstalledModuleVersion($id);

        $db = null;
        if ($dbVersion === null) {
            // No row in the "module" table: the module has never been installed.
            $db = ['from' => null, 'to' => $targetVersion];
        } elseif (version_compare($dbVersion, $targetVersion, '<')) {
            $db = ['from' => $dbVersion, 'to' => $targetVersion];
        }

        return ['code' => $code, 'db' => $db];
    }
}
