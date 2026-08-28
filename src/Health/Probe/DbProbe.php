<?php

namespace App\Health\Probe;

use App\Database\Connection;

/**
 * Reads instance state straight from the database.
 *
 * This must never reference an Omeka class, for the same reason App\Distribution\Inspector must not:
 * the update captures a snapshot through this probe before the code download, and PHP cannot reload
 * a class once it is declared. Loading Omeka here would leave the rest of the process running the
 * replaced code.
 *
 * Every method gathers facts and judges none of them. Thresholds live in the checks.
 */
class DbProbe
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Count resources by type and visibility.
     *
     * @return array<string, array{public: int, private: int}> Keyed by the resource_type value,
     *   e.g. "Omeka\Entity\Item".
     */
    public function resourceCounts(): array
    {
        $sql = 'SELECT resource_type, is_public, COUNT(*) AS total FROM resource GROUP BY resource_type, is_public';
        $counts = [];
        foreach ($this->connection->pdo()->query($sql) as $row) {
            $type = (string) $row['resource_type'];
            if (!isset($counts[$type])) {
                $counts[$type] = ['public' => 0, 'private' => 0];
            }
            $counts[$type][$row['is_public'] ? 'public' : 'private'] = (int) $row['total'];
        }
        ksort($counts);
        return $counts;
    }

    /**
     * Count public items.
     *
     * The only count an anonymous API request can be compared against, since it sees nothing else.
     */
    public function publicItemCount(): int
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM resource WHERE resource_type = ? AND is_public = 1'
        );
        $statement->execute(['Omeka\Entity\Item']);
        return (int) $statement->fetchColumn();
    }

    /**
     * @return string[] The slugs of the public sites.
     */
    public function publicSiteSlugs(): array
    {
        $sql = 'SELECT slug FROM site WHERE is_public = 1 ORDER BY id';
        return array_map('strval', $this->connection->pdo()->query($sql)->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The module table, as Omeka's own ModuleManagerFactory reads it.
     *
     * @return array<string, array{active: bool, version: string}> Keyed by module id.
     */
    public function moduleRows(): array
    {
        $rows = [];
        foreach ($this->connection->pdo()->query('SELECT id, is_active, version FROM module') as $row) {
            $rows[(string) $row['id']] = [
                'active' => (bool) $row['is_active'],
                'version' => (string) $row['version'],
            ];
        }
        ksort($rows);
        return $rows;
    }

    /**
     * @return string[] The migration versions recorded as applied, e.g. "20240103030617".
     */
    public function migrationVersions(): array
    {
        $sql = 'SELECT version FROM migration';
        return array_map('strval', $this->connection->pdo()->query($sql)->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Sample media rows that have stored files.
     *
     * Newest first rather than random, so two runs examine the same files and can be compared.
     *
     * @param int $limit How many rows to take.
     * @return array<int, array{id: int, storage_id: string, extension: ?string, media_type: ?string,
     *   has_original: bool, has_thumbnails: bool}>
     */
    public function sampleMedia(int $limit): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, storage_id, extension, media_type, has_original, has_thumbnails'
            . ' FROM media WHERE storage_id IS NOT NULL ORDER BY id DESC LIMIT :limit'
        );
        $statement->bindValue(':limit', max(0, $limit), \PDO::PARAM_INT);
        $statement->execute();

        $media = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $media[] = [
                'id' => (int) $row['id'],
                'storage_id' => (string) $row['storage_id'],
                'extension' => $row['extension'] !== null ? (string) $row['extension'] : null,
                'media_type' => $row['media_type'] !== null ? (string) $row['media_type'] : null,
                'has_original' => (bool) $row['has_original'],
                'has_thumbnails' => (bool) $row['has_thumbnails'],
            ];
        }
        return $media;
    }
}
