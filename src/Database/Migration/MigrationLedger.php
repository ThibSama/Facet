<?php

declare(strict_types=1);

namespace Facet\Database\Migration;

use Facet\Database\Database;

/**
 * The record of which migrations this database has already run.
 *
 * The ledger is not itself a migration: it has to exist before the first one
 * can be recorded, so it is created on demand with `IF NOT EXISTS`.
 */
final class MigrationLedger
{
    public const TABLE = 'schema_migrations';

    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function ensureExists(): void
    {
        $this->database->executeTrusted(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'version VARCHAR(50) NOT NULL,'
            . 'name VARCHAR(191) NOT NULL,'
            . 'checksum CHAR(64) NOT NULL,'
            . 'applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (version)'
            . ') ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci'
        );
    }

    public function exists(): bool
    {
        return $this->database->selectValue(
            'SELECT COUNT(*) FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = :name',
            ['name' => self::TABLE]
        ) > 0;
    }

    /**
     * @return array<string, array{version: string, name: string, checksum: string, applied_at: string}>
     *                            keyed by version
     */
    public function applied(): array
    {
        if (!$this->exists()) {
            return [];
        }

        $rows = $this->database->select(
            'SELECT version, name, checksum, applied_at FROM ' . self::TABLE . ' ORDER BY version ASC'
        );

        $applied = [];

        foreach ($rows as $row) {
            $version = (string) $row['version'];

            $applied[$version] = [
                'version' => $version,
                'name' => (string) $row['name'],
                'checksum' => (string) $row['checksum'],
                'applied_at' => (string) $row['applied_at'],
            ];
        }

        return $applied;
    }

    public function record(Migration $migration): void
    {
        $this->database->execute(
            'INSERT INTO ' . self::TABLE . ' (version, name, checksum) VALUES (:version, :name, :checksum)',
            [
                'version' => $migration->version(),
                'name' => $migration->name(),
                'checksum' => $migration->checksum(),
            ]
        );
    }
}
