<?php

declare(strict_types=1);

namespace Facet\Database\Migration;

use RuntimeException;

/**
 * Raised when the migration set and the recorded history disagree.
 *
 * Every constructor here describes a state the migrator refuses to work
 * through. The bias is deliberate: a schema tool that guesses is worse than
 * one that stops, because the damage is discovered later and against real data.
 */
final class MigrationException extends RuntimeException
{
    public static function unreadableDirectory(string $path): self
    {
        return new self(sprintf('Migration directory "%s" does not exist or is not readable.', $path));
    }

    public static function malformedFilename(string $filename): self
    {
        return new self(sprintf(
            'Migration "%s" is not named <version>_<name>.sql, e.g. 0001_create_users_table.sql.',
            $filename
        ));
    }

    public static function duplicateVersion(string $version): self
    {
        return new self(sprintf('Two migrations share version "%s".', $version));
    }

    public static function checksumMismatch(string $version, string $name): self
    {
        return new self(sprintf(
            'Migration %s (%s) has changed since it was applied. '
            . 'An applied migration is history and must not be edited: add a new migration instead.',
            $version,
            $name
        ));
    }

    public static function missingApplied(string $version): self
    {
        return new self(sprintf(
            'Migration %s is recorded as applied but its file is absent. '
            . 'The database is ahead of this checkout; refusing to continue.',
            $version
        ));
    }

    public static function empty(string $version): self
    {
        return new self(sprintf('Migration %s contains no executable statement.', $version));
    }
}
