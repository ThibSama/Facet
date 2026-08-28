<?php

declare(strict_types=1);

namespace Facet\Database\Migration;

/**
 * The ordered set of migration files on disk.
 */
final class MigrationRepository
{
    /** @var list<Migration> */
    private array $migrations;

    /**
     * @param list<Migration> $migrations
     */
    private function __construct(array $migrations)
    {
        $this->migrations = $migrations;
    }

    /**
     * @throws MigrationException when the directory is unreadable, a filename
     *                            is malformed, or two files share a version
     */
    public static function fromDirectory(string $path): self
    {
        if (!is_dir($path)) {
            throw MigrationException::unreadableDirectory($path);
        }

        $files = glob(rtrim($path, '/') . '/*.sql');

        if ($files === false) {
            throw MigrationException::unreadableDirectory($path);
        }

        // Zero-padded versions sort correctly as strings, and sorting the
        // filenames is what makes the order a property of the repository
        // rather than of the filesystem's directory order.
        sort($files, SORT_STRING);

        $migrations = [];
        $seen = [];

        foreach ($files as $file) {
            $migration = Migration::fromFile($file);
            $version = $migration->version();

            if (isset($seen[$version])) {
                throw MigrationException::duplicateVersion($version);
            }

            $seen[$version] = true;
            $migrations[] = $migration;
        }

        return new self($migrations);
    }

    /**
     * @param list<Migration> $migrations
     */
    public static function fromMigrations(array $migrations): self
    {
        return new self($migrations);
    }

    /**
     * @return list<Migration>
     */
    public function all(): array
    {
        return $this->migrations;
    }

    public function count(): int
    {
        return count($this->migrations);
    }
}
