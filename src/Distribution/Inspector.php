<?php

namespace App\Distribution;

use App\Database\Connection;

/**
 * Reads the state of an installed Omeka S instance without bootstrapping it.
 *
 * The update commands have to audit the installation *before* downloading new code. Bootstrapping
 * Omeka to do that would load its classes into the running process, and PHP cannot replace a class
 * once it is declared, so the freshly downloaded code would be ignored for the rest of the run.
 * Every read here goes straight to the filesystem or to a raw database connection
 * (App\Database\Connection), never through Omeka.
 *
 * This deliberately mirrors what Omeka itself does: \Omeka\Mvc\Status for the core versions,
 * \Omeka\Service\ModuleManagerFactory for the modules and \Omeka\Service\ThemeManagerFactory for
 * the themes.
 */
class Inspector
{
    private string $publicDir;

    private Connection $connection;

    public function __construct(string $rootDir, ?Connection $connection = null)
    {
        $this->publicDir = $rootDir . '/public';
        $this->connection = $connection ?? new Connection($rootDir);
    }

    /**
     * Get the Omeka S version of the code currently on disk.
     *
     * Read from the VERSION constant of the core module, the same value \Omeka\Mvc\Status::getVersion()
     * reports once the application is running.
     *
     * @return string|null Null when the core is not present or the constant cannot be read.
     */
    public function getCoreVersion(): ?string
    {
        $moduleFile = $this->publicDir . '/application/Module.php';
        if (!is_readable($moduleFile)) {
            return null;
        }
        if (!preg_match('/const\s+VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/', file_get_contents($moduleFile), $matches)) {
            return null;
        }
        return $matches[1];
    }

    /**
     * Get the Omeka S version recorded in the database.
     *
     * This is the version the database schema is at, which lags the code version between a code
     * update and the migrations being run.
     *
     * @return string|null Null when the setting is absent.
     */
    public function getInstalledCoreVersion(): ?string
    {
        $statement = $this->connection->pdo()->prepare('SELECT value FROM setting WHERE id = ?');
        $statement->execute(['version']);
        $value = $statement->fetchColumn();
        if ($value === false) {
            return null;
        }
        // Settings are stored as JSON.
        $version = json_decode($value);
        return is_string($version) ? $version : null;
    }

    /**
     * Get the version of a module on disk.
     *
     * @param string $id The module identifier, i.e. its directory name.
     * @return string|null Null when the module is absent or its INI is unusable.
     */
    public function getModuleVersion(string $id): ?string
    {
        return $this->getIniVersion($this->publicDir . '/modules/' . $id . '/config/module.ini');
    }

    /**
     * Get the version of a module recorded in the database.
     *
     * This is the version the module's schema and data are at, which lags the version on disk
     * between a code update and the module upgrade being run. Mirrors what
     * \Omeka\Service\ModuleManagerFactory reads out of the "module" table.
     *
     * @param string $id The module identifier, i.e. its directory name.
     * @return string|null Null when the module has no row, i.e. it has never been installed.
     */
    public function getInstalledModuleVersion(string $id): ?string
    {
        $statement = $this->connection->pdo()->prepare('SELECT version FROM module WHERE id = ?');
        $statement->execute([$id]);
        $value = $statement->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }
        return (string) $value;
    }

    /**
     * Check whether a theme directory exists.
     *
     * ThemeManagerFactory registers every directory under themes/, so the directory existing is
     * exactly what \Omeka\Site\Theme\Manager::isRegistered() reports.
     *
     * @param string $id The theme identifier, i.e. its directory name.
     */
    public function isThemeRegistered(string $id): bool
    {
        return is_dir($this->publicDir . '/themes/' . $id);
    }

    /**
     * Get the version of a theme on disk.
     *
     * @param string $id The theme identifier, i.e. its directory name.
     * @return string|null Null when the theme is absent or its INI is unusable.
     */
    public function getThemeVersion(string $id): ?string
    {
        return $this->getIniVersion($this->publicDir . '/themes/' . $id . '/config/theme.ini');
    }

    /**
     * Verify a user's credentials.
     *
     * Stands in for authenticating against Omeka, so that bad credentials are reported before a
     * large download rather than after it.
     *
     * @param string $email The user email.
     * @param string $password The user password.
     */
    public function verifyCredentials(string $email, string $password): bool
    {
        $statement = $this->connection->pdo()->prepare('SELECT password_hash, is_active FROM user WHERE email = ?');
        $statement->execute([$email]);
        $user = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!$user || !$user['is_active'] || $user['password_hash'] === null) {
            return false;
        }
        return password_verify($password, $user['password_hash']);
    }

    /**
     * Read the version out of a module or theme INI file.
     *
     * @param string $file Path to the INI file.
     * @return string|null Null when the file is absent, unreadable or has no version under [info].
     */
    private function getIniVersion(string $file): ?string
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $ini = @parse_ini_file($file, true);
        if (!is_array($ini) || !isset($ini['info']['version'])) {
            return null;
        }
        return (string) $ini['info']['version'];
    }
}
