<?php

namespace App\Health;

use App\Database\Connection;
use App\Distribution\Inspector;
use App\Distribution\Manifest;
use App\Health\Check\CoreVersionCheck;
use App\Health\Check\MigrationCheck;
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
 */
class CheckerFactory
{
    public const USER_AGENT = 'ngc-omeka-health-check/1.0';

    private ?DbProbe $dbProbe = null;

    private ?HttpProbe $httpProbe = null;

    private ?Inspector $inspector = null;

    public function __construct(
        private string $rootDir,
        private ?string $baseUrl,
        private int $timeoutSeconds,
        private bool $insecure,
        private int $slowMs,
        private int $mediaSamples,
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

        $runner->add(new CoreVersionCheck($this->inspector()));
        if ($this->dbProbe !== null) {
            $runner->add(new MigrationCheck($this->rootDir, $this->dbProbe));
        }

        return $runner;
    }

    /**
     * The distribution manifest, for the checks that compare against it.
     */
    public function manifest(): Manifest
    {
        return new Manifest($this->rootDir);
    }

    public function inspector(): Inspector
    {
        return $this->inspector ??= new Inspector($this->rootDir, new Connection($this->rootDir));
    }
}
