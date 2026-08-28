<?php

declare(strict_types=1);

/**
 * Apply pending database migrations.
 *
 *     php tools/migrate.php            apply everything pending
 *     php tools/migrate.php --status   report without changing anything
 *
 * Local/CLI only. There is deliberately no HTTP equivalent: an installer
 * reachable over the network is a standing invitation, and this needs to run
 * exactly once per deploy by someone with shell access.
 *
 * Credentials come from DB_DSN / DB_USERNAME / DB_PASSWORD in the environment
 * or `.env`. None of them is ever printed.
 */

use Facet\Config\Config;
use Facet\Config\MissingConfigurationException;
use Facet\Database\Database;
use Facet\Database\DatabaseException;
use Facet\Database\Migration\MigrationException;
use Facet\Database\Migration\Migrator;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__);
/** @var list<string> $arguments */
$arguments = array_slice((array) ($_SERVER['argv'] ?? []), 1);
$statusOnly = in_array('--status', $arguments, true);

try {
    $database = Database::fromConfig(Config::fromEnvironment($root));
    $migrator = Migrator::default($database, $root);

    $pending = $migrator->pending();

    if ($statusOnly) {
        $applied = $migrator->ledger()->applied();

        fwrite(STDOUT, sprintf("Applied: %d\n", count($applied)));

        foreach ($applied as $record) {
            fwrite(STDOUT, sprintf("  %s %s (%s)\n", $record['version'], $record['name'], $record['applied_at']));
        }

        fwrite(STDOUT, sprintf("Pending: %d\n", count($pending)));

        foreach ($pending as $migration) {
            fwrite(STDOUT, sprintf("  %s %s\n", $migration->version(), $migration->name()));
        }

        exit(0);
    }

    if ($pending === []) {
        fwrite(STDOUT, "Nothing to migrate; the schema is up to date.\n");
        exit(0);
    }

    foreach ($migrator->migrate() as $version) {
        fwrite(STDOUT, sprintf("Applied %s\n", $version));
    }

    fwrite(STDOUT, "Migration complete.\n");
    exit(0);
} catch (MissingConfigurationException $e) {
    fwrite(STDERR, 'Configuration error: ' . $e->getMessage() . "\n");
    exit(2);
} catch (MigrationException $e) {
    fwrite(STDERR, 'Migration refused: ' . $e->getMessage() . "\n");
    exit(3);
} catch (DatabaseException $e) {
    // getMessage() and driverDetail() are both credential-scrubbed.
    fwrite(STDERR, 'Database error: ' . $e->getMessage() . "\n");
    fwrite(STDERR, '  ' . $e->driverDetail() . "\n");
    exit(4);
}
