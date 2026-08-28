<?php

namespace App\Health;

use App\Database\Connection;
use App\Distribution\Inspector;
use App\Distribution\Manifest;
use App\Health\Check\ApiCheck;
use App\Health\Check\CoreVersionCheck;
use App\Health\Check\ManifestCheck;
use App\Health\Check\MediaCheck;
use App\Health\Check\MigrationCheck;
use App\Health\Check\ModuleStateCheck;
use App\Health\Check\PageCheck;
use App\Health\Probe\DbProbe;
use App\Health\Probe\HttpProbe;

/**
 * Assembles the probes and the check list.
 *
 * Exists so that "health:check" and "update --health-check" run exactly the same set. Registering
 * the checks in two commands would let the two drift apart, and a health check that verifies
 * something different depending on how it was invoked is worse than none.
 *
 * A probe is null when it is not configured, which the Runner turns into a visible SKIP. A probe
 * that is configured but broken is not null: the check runs, throws, and is reported as a FAIL.
 *
 * The option defaults live here rather than in the commands' configure() for the same reason the
 * check list does: "health:check" and "update --health-check" both need them, and defaults defined
 * twice drift apart silently.
 */
class CheckerFactory
{
    public const USER_AGENT = 'ngc-omeka-health-check/1.0';

    public const DEFAULT_TIMEOUT_SECONDS = 30;

    public const DEFAULT_INSECURE = false;

    public const DEFAULT_SLOW_MS = 5000;

    public const DEFAULT_MEDIA_SAMPLES = 5;

    private ?DbProbe $dbProbe = null;

    private ?HttpProbe $httpProbe = null;

    private ?Inspector $inspector = null;

    private ?Manifest $manifest = null;

    public function __construct(
        private string $rootDir,
        private ?string $baseUrl,
        private int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private bool $insecure = self::DEFAULT_INSECURE,
        private int $slowMs = self::DEFAULT_SLOW_MS,
        private int $mediaSamples = self::DEFAULT_MEDIA_SAMPLES,
    ) {
        $connection = new Connection($this->rootDir);
        if ($connection->isConfigured()) {
            $this->dbProbe = new DbProbe($connection);
        }
        if ($this->baseUrl !== null && $this->baseUrl !== '') {
            $this->httpProbe = new HttpProbe($this->baseUrl, $this->timeoutSeconds, $this->insecure, self::USER_AGENT);
        }
    }

    public function baseUrl(): ?string
    {
        return $this->httpProbe?->baseUrl();
    }

    public function dbProbe(): ?DbProbe
    {
        return $this->dbProbe;
    }

    public function httpProbe(): ?HttpProbe
    {
        return $this->httpProbe;
    }

    public function createRunner(): Runner
    {
        $runner = new Runner([
            'db' => $this->dbProbe !== null,
            'http' => $this->httpProbe !== null,
        ]);

        // Passed as a closure, not an object: reading distribution.json can throw, and constructing
        // it here would throw during check construction, outside the Runner's try/catch and outside
        // anything either command wraps. One JSON typo in a hand-edited file would then take out the
        // whole run - including the four checks that never look at the manifest - and, after a
        // successful "update --health-check", would kill the command before it printed that the
        // update had in fact been applied. Deferred into run(), it is a FAIL like any other.
        $manifest = fn (): Manifest => $this->manifest();

        // Registration order is display order: the console renderer groups by the check id prefix
        // in the order the results arrive.
        $runner->add(new CoreVersionCheck($this->inspector()));
        $runner->add(new MigrationCheck($this->rootDir, $this->dbProbe));
        $runner->add(new ModuleStateCheck($this->rootDir, $this->inspector(), $this->dbProbe, $manifest));
        $runner->add(new ManifestCheck($manifest, $this->inspector()));
        $runner->add(new ApiCheck($this->httpProbe, $this->inspector(), $this->dbProbe, $this->slowMs));
        $runner->add(new PageCheck($this->httpProbe, $this->dbProbe, $this->slowMs));
        $runner->add(new MediaCheck($this->dbProbe, $this->httpProbe, $this->mediaSamples));

        return $runner;
    }

    /**
     * The distribution manifest, for the checks that compare against it.
     *
     * @throws \RuntimeException if distribution.json is missing or unparseable. Callers reach this
     *   through the closure createRunner() hands the checks, so the throw lands inside the Runner.
     */
    public function manifest(): Manifest
    {
        return $this->manifest ??= new Manifest($this->rootDir);
    }

    /**
     * The base URL recorded in config/config.json, or null when there is none.
     *
     * Lives here because both entry points need it and neither should have to know that the key is
     * optional, that config.json may not exist at all, and that the trailing slash is normalised.
     * The health checker is the only thing that reads this key, and an absent one is not an error:
     * the HTTP checks skip and everything local still runs.
     */
    public static function baseUrlFromConfig(string $rootDir): ?string
    {
        $configPath = $rootDir . '/config/config.json';
        if (!is_readable($configPath)) {
            return null;
        }
        $config = json_decode((string) file_get_contents($configPath), true);
        if (!is_array($config) || !isset($config['url']) || !is_string($config['url']) || $config['url'] === '') {
            return null;
        }
        return rtrim($config['url'], '/');
    }

    public function inspector(): Inspector
    {
        return $this->inspector ??= new Inspector($this->rootDir, new Connection($this->rootDir));
    }
}
