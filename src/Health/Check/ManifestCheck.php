<?php

namespace App\Health\Check;

use App\Distribution\Inspector;
use App\Distribution\Manifest;
use App\Health\Check;
use App\Health\Result;

/**
 * Compares what is on disk with distribution.json.
 *
 * Answers whether this instance is the distribution it claims to be. Drift is advisory rather than
 * a failure: an instance can legitimately sit ahead of or behind the manifest between distribution
 * releases. A missing core is the exception, because nothing else can be true without it.
 *
 * Reads only the code side, so it needs no database and no URL and runs in every configuration.
 */
class ManifestCheck implements Check
{
    private const ID = 'distribution.manifest';

    public function __construct(private Manifest $manifest, private Inspector $inspector)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Manifest conformance';
    }

    public function requires(): array
    {
        return [];
    }

    public function run(): array
    {
        $core = $this->manifest->getCore();
        $coreOnDisk = $this->inspector->getCoreVersion();

        if ($coreOnDisk === null) {
            return [Result::fail(self::ID, 'Manifest conformance: the Omeka S core is not present on disk', [
                sprintf('distribution.json expects %s.', $core['version']),
                'Run "php console install" or "php console update:core".',
            ])];
        }

        $drift = [];

        if ($coreOnDisk !== $core['version']) {
            $drift[] = sprintf('core: manifest %s, disk %s', $core['version'], $coreOnDisk);
        }

        foreach ($this->manifest->getModules() as $module) {
            $onDisk = $this->inspector->getModuleVersion($module['name']);
            if ($onDisk === null) {
                $drift[] = sprintf('module %s: manifest %s, not on disk', $module['name'], $module['version']);
            } elseif ($onDisk !== $module['version']) {
                $drift[] = sprintf('module %s: manifest %s, disk %s', $module['name'], $module['version'], $onDisk);
            }
        }

        foreach ($this->manifest->getThemes() as $theme) {
            if (!$this->inspector->isThemeRegistered($theme['name'])) {
                $drift[] = sprintf('theme %s: manifest %s, not on disk', $theme['name'], $theme['version']);
                continue;
            }
            $onDisk = $this->inspector->getThemeVersion($theme['name']);
            if ($onDisk !== null && $onDisk !== $theme['version']) {
                $drift[] = sprintf('theme %s: manifest %s, disk %s', $theme['name'], $theme['version'], $onDisk);
            }
        }

        $summary = sprintf(
            'core, %d module(s) and %d theme(s)',
            count($this->manifest->getModules()),
            count($this->manifest->getThemes())
        );

        if ($drift === []) {
            return [Result::pass(self::ID, 'Manifest conformance: ' . $summary . ' at their manifest versions')];
        }

        return [Result::warn(
            self::ID,
            sprintf('Manifest conformance: %d component(s) differ from distribution.json', count($drift)),
            array_merge($drift, ['Run "php console update" to bring the instance to the manifest versions.'])
        )];
    }
}
