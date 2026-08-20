<?php

namespace App\Distribution;

/**
 * Typed reader for distribution.json.
 *
 * The manifest is the source of truth for what belongs to this distribution and which version each
 * component should be at. Anything not listed here is not ours: the update commands never download
 * it, and the granular commands refuse to be pointed at it.
 */
class Manifest
{
    private array $data;

    private array $modules = [];

    private array $themes = [];

    /**
     * @param string $rootDir The repository root, i.e. the directory holding distribution.json.
     *
     * @throws \RuntimeException if the manifest is missing or unreadable.
     */
    public function __construct(string $rootDir)
    {
        $file = $rootDir . '/distribution.json';
        if (!file_exists($file)) {
            throw new \RuntimeException('distribution.json not found.');
        }
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            throw new \RuntimeException('distribution.json could not be parsed.');
        }
        $this->data = $data;

        // Index the modules and themes by their ID, which is what every caller looks them up by.
        foreach ($data['modules'] ?? [] as $module) {
            if (isset($module['name'])) {
                $this->modules[$module['name']] = $module;
            }
        }
        foreach ($data['themes'] ?? [] as $theme) {
            if (isset($theme['name'])) {
                $this->themes[$theme['name']] = $theme;
            }
        }
    }

    /**
     * Get the core entry.
     *
     * @return array With "url" and "version" keys.
     *
     * @throws \RuntimeException if the manifest has no usable core entry.
     */
    public function getCore(): array
    {
        if (!isset($this->data['core']['version'], $this->data['core']['url'])) {
            throw new \RuntimeException('distribution.json does not define the core version and URL.');
        }
        return $this->data['core'];
    }

    /**
     * Get every module in the distribution, keyed by module ID.
     */
    public function getModules(): array
    {
        return $this->modules;
    }

    /**
     * Get every theme in the distribution, keyed by theme ID.
     */
    public function getThemes(): array
    {
        return $this->themes;
    }

    /**
     * Get a single module entry.
     *
     * @return array|null Null when the module is not part of the distribution.
     */
    public function getModule(string $id): ?array
    {
        return $this->modules[$id] ?? null;
    }

    /**
     * Get a single theme entry.
     *
     * @return array|null Null when the theme is not part of the distribution.
     */
    public function getTheme(string $id): ?array
    {
        return $this->themes[$id] ?? null;
    }

    /**
     * Resolve the module IDs an update should cover.
     *
     * @param array|null $ids The IDs nominated on the command line, or null/empty for all of them.
     * @return array The resolved module IDs.
     *
     * @throws \RuntimeException if any nominated ID is not part of the distribution. Every offending
     *   ID is listed, so one run reports every typo rather than the first.
     */
    public function resolveModuleIds(?array $ids): array
    {
        return $this->resolveIds($ids, $this->modules, 'module');
    }

    /**
     * Resolve the theme IDs an update should cover.
     *
     * @param array|null $ids The IDs nominated on the command line, or null/empty for all of them.
     * @return array The resolved theme IDs.
     *
     * @throws \RuntimeException if any nominated ID is not part of the distribution.
     */
    public function resolveThemeIds(?array $ids): array
    {
        return $this->resolveIds($ids, $this->themes, 'theme');
    }

    /**
     * Validate nominated IDs against the manifest, or fall back to everything in it.
     *
     * @throws \RuntimeException if any nominated ID is unknown.
     */
    private function resolveIds(?array $ids, array $available, string $label): array
    {
        if (empty($ids)) {
            return array_keys($available);
        }

        // Report every unknown ID at once. A typo that silently became a no-op would report success
        // while doing nothing, which is the opposite of what a targeted update is for.
        $unknown = array_values(array_diff($ids, array_keys($available)));
        if ($unknown) {
            throw new \RuntimeException(sprintf(
                'The following %s(s) are not part of this distribution: %s. Only %ss defined in '
                . 'distribution.json can be updated.',
                $label,
                implode(', ', $unknown),
                $label
            ));
        }

        return array_values(array_unique($ids));
    }
}
