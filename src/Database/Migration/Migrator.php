<?php

declare(strict_types=1);

namespace Facet\Database\Migration;

use Facet\Database\Database;

/**
 * Brings a database up to the schema this checkout describes.
 *
 * The contract is narrow and worth stating exactly:
 *
 * - Migrations run in version order, once each. A second run of {@see migrate()}
 *   applies nothing and reports an empty list.
 * - Before anything runs, the recorded history is checked against the files.
 *   An applied migration whose text has changed, or whose file has vanished,
 *   stops the run — see {@see verify()}.
 * - A blank database reaches the same schema as a database migrated
 *   incrementally, because both replay the same ordered statements.
 *
 * On atomicity, honestly: **a migration is not atomic.** MariaDB commits DDL
 * implicitly, so wrapping `CREATE TABLE` in a transaction buys nothing — a
 * migration that fails on its third statement leaves the first two applied and
 * is *not* recorded in the ledger. That combination is deliberate: re-running
 * will fail loudly on the already-created object rather than silently skip the
 * remainder. Recovery is a human decision, so the migrator does not pretend to
 * make it.
 */
final class Migrator
{
    private Database $database;

    private MigrationRepository $migrations;

    private MigrationLedger $ledger;

    public function __construct(Database $database, MigrationRepository $migrations)
    {
        $this->database = $database;
        $this->migrations = $migrations;
        $this->ledger = new MigrationLedger($database);
    }

    public static function default(Database $database, ?string $projectRoot = null): self
    {
        $projectRoot ??= dirname(__DIR__, 3);

        return new self($database, MigrationRepository::fromDirectory(
            $projectRoot . '/database/migrations'
        ));
    }

    public function ledger(): MigrationLedger
    {
        return $this->ledger;
    }

    /**
     * Migrations this database has not yet run, in order.
     *
     * @return list<Migration>
     *
     * @throws MigrationException when history and files disagree
     */
    public function pending(): array
    {
        $this->verify();

        $applied = $this->ledger->applied();

        return array_values(array_filter(
            $this->migrations->all(),
            static fn (Migration $m): bool => !isset($applied[$m->version()])
        ));
    }

    /**
     * Fail closed on any disagreement between the ledger and the files.
     *
     * @throws MigrationException
     */
    public function verify(): void
    {
        $available = [];

        foreach ($this->migrations->all() as $migration) {
            $available[$migration->version()] = $migration;
        }

        foreach ($this->ledger->applied() as $version => $record) {
            if (!isset($available[$version])) {
                throw MigrationException::missingApplied($version);
            }

            if (!hash_equals($record['checksum'], $available[$version]->checksum())) {
                throw MigrationException::checksumMismatch($version, $available[$version]->name());
            }
        }
    }

    /**
     * Apply every pending migration.
     *
     * @return list<string> the versions applied by *this* call
     *
     * @throws MigrationException
     * @throws \Facet\Database\DatabaseException
     */
    public function migrate(): array
    {
        $this->ledger->ensureExists();

        $applied = [];

        foreach ($this->pending() as $migration) {
            foreach ($migration->statements() as $statement) {
                $this->database->executeTrusted($statement);
            }

            // Recorded only once every statement succeeded. A partially
            // applied migration therefore stays pending and re-running fails
            // loudly rather than continuing past a broken schema.
            $this->ledger->record($migration);

            $applied[] = $migration->version();
        }

        return $applied;
    }
}
