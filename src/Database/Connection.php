<?php

namespace App\Database;

/**
 * The connection to the Omeka S database.
 *
 * Extracted from App\Distribution\Inspector so that the update audit and the health checker share
 * one implementation of the connection details. Like Inspector, this deliberately does not go
 * through Omeka: it is used before and during a code download, when Omeka's own classes must not be
 * loaded.
 *
 * Mirrors public/application/config/application.config.php: the details come from
 * config/database.ini, and the OMEKA_DB_CONNECTION_URL environment variable overrides them.
 */
class Connection
{
    private string $publicDir;

    private ?\PDO $pdo = null;

    public function __construct(string $rootDir)
    {
        $this->publicDir = $rootDir . '/public';
    }

    /**
     * Whether connection details exist at all.
     *
     * Distinguishes "no database configured" from "configured but unreachable". The health checker
     * skips its database checks in the first case and fails them in the second.
     */
    public function isConfigured(): bool
    {
        try {
            $this->getParams();
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    /**
     * Get the connection, opening it on first use.
     *
     * @throws \RuntimeException if the connection details are missing or the connection fails.
     */
    public function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $params = $this->getParams();

        $dsn = 'mysql:';
        if (!empty($params['unix_socket'])) {
            $dsn .= 'unix_socket=' . $params['unix_socket'];
        } else {
            $dsn .= 'host=' . ($params['host'] ?? 'localhost');
            if (!empty($params['port'])) {
                $dsn .= ';port=' . $params['port'];
            }
        }
        $dsn .= ';dbname=' . ($params['dbname'] ?? '') . ';charset=utf8mb4';

        try {
            $this->pdo = new \PDO($dsn, $params['user'] ?? '', $params['password'] ?? '', [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException $e) {
            throw new \RuntimeException('Could not connect to the Omeka S database: ' . $e->getMessage());
        }

        return $this->pdo;
    }

    /**
     * Get the connection details.
     *
     * @throws \RuntimeException if no connection details can be found.
     */
    private function getParams(): array
    {
        $params = [];

        $iniFile = $this->publicDir . '/config/database.ini';
        if (is_readable($iniFile)) {
            $ini = @parse_ini_file($iniFile);
            if (is_array($ini)) {
                $params = $ini;
            }
        }

        $url = getenv('OMEKA_DB_CONNECTION_URL') ?: ($params['url'] ?? null);
        if ($url) {
            $parsed = parse_url($url);
            if ($parsed === false) {
                throw new \RuntimeException('The Omeka S database connection URL could not be parsed.');
            }
            $params = [
                'host' => $parsed['host'] ?? 'localhost',
                'port' => $parsed['port'] ?? null,
                'user' => isset($parsed['user']) ? rawurldecode($parsed['user']) : null,
                'password' => isset($parsed['pass']) ? rawurldecode($parsed['pass']) : null,
                'dbname' => isset($parsed['path']) ? ltrim($parsed['path'], '/') : null,
            ];
        }

        if (empty($params['dbname'])) {
            throw new \RuntimeException(
                'Omeka S database connection details not found. Expected them in public/config/database.ini.'
            );
        }

        return $params;
    }
}
