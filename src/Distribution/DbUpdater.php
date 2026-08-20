<?php

namespace App\Distribution;

use App\Omeka;

/**
 * Applies pending database work to a running Omeka S instance.
 *
 * This is the only part of the distribution updater that bootstraps Omeka, and it must not be
 * reached until the new code is on disk: PHP cannot reload a class once it is declared, so an
 * instance bootstrapped earlier would run the old Module::upgrade() methods and record the old core
 * version. See App\Distribution\Inspector for how the pre-download audit avoids that.
 *
 * The audit here reads live state from the service manager rather than trusting the plan the
 * Auditor built before the download, because by this point the service manager is authoritative.
 */
class DbUpdater
{
    /**
     * Module states in which no install or upgrade can be applied, whatever the version numbers say.
     *
     * A module in one of these is not merely up to date - Omeka has refused to load it, so the
     * pending database work for it silently cannot happen. Reporting that is the difference between
     * a command that did nothing and a command that did nothing while saying it succeeded.
     *
     * Deliberately a method rather than a class constant: a constant referencing \Omeka\Module\Manager
     * is resolved when this class is loaded, which is before Omeka has been bootstrapped and its
     * autoloader registered.
     */
    private function getBlockedStates(): array
    {
        return [
            \Omeka\Module\Manager::STATE_INVALID_MODULE,
            \Omeka\Module\Manager::STATE_INVALID_INI,
            \Omeka\Module\Manager::STATE_INVALID_OMEKA_VERSION,
            \Omeka\Module\Manager::STATE_NOT_FOUND,
        ];
    }

    /**
     * Audit the core database against the core code now loaded.
     *
     * @return array|null ["from" => ?string, "to" => string], or null when the database is current.
     */
    public function auditCore(): ?array
    {
        $status = $this->getStatus();
        if (!$status->needsVersionUpdate()) {
            return null;
        }
        return [
            'from' => $status->getInstalledVersion(),
            'to' => $status->getVersion(),
        ];
    }

    /**
     * Audit modules that need installing or upgrading.
     *
     * @param array|null $ids Restrict the audit to these module IDs. Null covers every module the
     *   module manager knows about, including ones that are not part of this distribution - which is
     *   what the unscoped "update:db" command has always done.
     * @return array Keyed by module ID, each ["from" => ?string, "to" => string]. A null "from"
     *   means the module has never been installed.
     */
    public function auditModules(?array $ids = null): array
    {
        $updates = [];
        /** @var \Omeka\Module\Module $module */
        foreach ($this->getModuleManager()->getModules() as $module) {
            if ($ids !== null && !in_array($module->getId(), $ids, true)) {
                continue;
            }
            $state = $module->getState();
            if ($state === \Omeka\Module\Manager::STATE_NEEDS_UPGRADE) {
                $updates[$module->getId()] = [
                    'from' => $module->getDb('version'),
                    'to' => $module->getIni('version'),
                ];
            } elseif ($state === \Omeka\Module\Manager::STATE_NOT_INSTALLED) {
                $updates[$module->getId()] = [
                    'from' => null,
                    'to' => $module->getIni('version'),
                ];
            }
        }
        return $updates;
    }

    /**
     * List the module IDs the module manager knows about.
     *
     * Used to report which modules an update deliberately left alone.
     */
    public function getKnownModuleIds(): array
    {
        return array_keys($this->getModuleManager()->getModules());
    }

    /**
     * Run pending core migrations and record the new core version.
     *
     * @throws \RuntimeException if a migration fails.
     */
    public function updateCore(): void
    {
        $serviceManager = Omeka::getApp()->getServiceManager();
        $status = $this->getStatus();

        if ($status->needsMigration()) {
            /** @var \Omeka\Db\Migration\Manager $migrationManager */
            $migrationManager = $serviceManager->get('Omeka\MigrationManager');
            try {
                $migrationManager->upgrade();
            } catch (\Exception $e) {
                throw new \RuntimeException(
                    'Migration failed: ' . $e->getMessage()
                    . ' Please try to run the core migration through the UI.',
                    0,
                    $e
                );
            }
        }

        /** @var \Omeka\Settings\Settings $settings */
        $settings = $serviceManager->get('Omeka\Settings');
        $settings->set('version', $status->getVersion());
    }

    /**
     * Install or upgrade a single module, whichever its current state calls for.
     *
     * @param string $id The module ID.
     *
     * @throws \RuntimeException if the module is missing, or the install or upgrade fails.
     */
    public function updateModule(string $id): void
    {
        $moduleManager = $this->getModuleManager();
        $module = $moduleManager->getModule($id);
        if (!$module) {
            throw new \RuntimeException("Module {$id} could not be found.");
        }

        $state = $module->getState();
        try {
            if ($state === \Omeka\Module\Manager::STATE_NOT_INSTALLED) {
                $moduleManager->install($module);
            } elseif ($state === \Omeka\Module\Manager::STATE_NEEDS_UPGRADE) {
                $moduleManager->upgrade($module);
            }
            // Any other state means there is nothing to apply.
        } catch (\Exception $e) {
            $verb = $state === \Omeka\Module\Manager::STATE_NOT_INSTALLED ? 'installation' : 'update';
            throw new \RuntimeException("Module {$verb} failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Describe a module as the running module manager sees it.
     *
     * @return array|null Null when the module manager does not know the module at all. Otherwise
     *   "state", whether that state is "blocked", the "ini" and "db" versions, and the module's
     *   Omeka version constraint.
     */
    public function getModuleStatus(string $id): ?array
    {
        $module = $this->getModuleManager()->getModule($id);
        if (!$module) {
            return null;
        }
        $state = $module->getState();
        return [
            'state' => $state,
            'blocked' => in_array($state, $this->getBlockedStates(), true),
            'ini' => $module->getIni('version'),
            'db' => $module->getDb('version'),
            'constraint' => $module->getIni('omeka_version_constraint'),
        ];
    }

    /**
     * @return \Omeka\Mvc\Status
     */
    private function getStatus()
    {
        return Omeka::getApp()->getServiceManager()->get('Omeka\Status');
    }

    /**
     * @return \Omeka\Module\Manager
     */
    private function getModuleManager()
    {
        return Omeka::getApp()->getServiceManager()->get('Omeka\ModuleManager');
    }
}
