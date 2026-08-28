<?php

namespace App\Health\Check;

use App\Distribution\Inspector;
use App\Health\Check;
use App\Health\Result;

/**
 * Compares the core version on disk with the version the database is migrated to.
 *
 * The two move independently: a code update advances the first and the migrations advance the
 * second. Any gap means an update stopped half way.
 */
class CoreVersionCheck implements Check
{
    private const ID = 'core.version';

    public function __construct(private Inspector $inspector)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Core version';
    }

    public function requires(): array
    {
        return ['db'];
    }

    public function run(): array
    {
        $onDisk = $this->inspector->getCoreVersion();
        $inDatabase = $this->inspector->getInstalledCoreVersion();

        if ($onDisk === null) {
            return [Result::fail(self::ID, 'Core version: cannot read the version from disk', [
                'Expected the VERSION constant in public/application/Module.php.',
            ])];
        }

        if ($inDatabase === null) {
            return [Result::fail(self::ID, 'Core version: no version recorded in the database', [
                'The "version" row is missing from the setting table. Omeka S may not be installed.',
            ])];
        }

        if ($onDisk === $inDatabase) {
            return [Result::pass(self::ID, sprintf('Core version: %s, code and database agree', $onDisk))];
        }

        $comparison = version_compare($inDatabase, $onDisk);
        $detail = $comparison < 0
            ? ['The database is behind the code, so the core migrations have not been run. Run "php console update:db".']
            : ['The database is ahead of the code, so the core was downgraded under a migrated schema. Restore the newer core.'];

        return [Result::fail(
            self::ID,
            sprintf('Core version: code is %s but the database is at %s', $onDisk, $inDatabase),
            $detail
        )];
    }
}
