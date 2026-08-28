<?php

declare(strict_types=1);

namespace Facet\Database\Migration;

/**
 * One numbered SQL file, and the fingerprint that proves it has not changed.
 */
final class Migration
{
    private string $version;

    private string $name;

    private string $sql;

    private function __construct(string $version, string $name, string $sql)
    {
        $this->version = $version;
        $this->name = $name;
        $this->sql = $sql;
    }

    /**
     * @throws MigrationException when the filename is not `<version>_<name>.sql`
     */
    public static function fromFile(string $path): self
    {
        $filename = basename($path);

        if (preg_match('/^(\d+)_([a-z0-9_]+)\.sql$/', $filename, $matches) !== 1) {
            throw MigrationException::malformedFilename($filename);
        }

        $sql = file_get_contents($path);

        if ($sql === false) {
            throw MigrationException::unreadableDirectory($path);
        }

        return new self($matches[1], $matches[2], $sql);
    }

    public static function fromParts(string $version, string $name, string $sql): self
    {
        return new self($version, $name, $sql);
    }

    public function version(): string
    {
        return $this->version;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function sql(): string
    {
        return $this->sql;
    }

    /**
     * A fingerprint over the migration's *meaning*.
     *
     * Line endings are normalised and trailing whitespace stripped so that a
     * checkout on another platform does not read as tampering, while any
     * change to an actual statement changes the digest.
     */
    public function checksum(): string
    {
        $normalised = (string) preg_replace('/[ \t]+$/m', '', str_replace(["\r\n", "\r"], "\n", $this->sql));

        return hash('sha256', trim($normalised));
    }

    /**
     * @return list<string>
     *
     * @throws MigrationException when the file holds nothing to run
     */
    public function statements(): array
    {
        $statements = SqlStatements::split($this->sql);

        if ($statements === []) {
            throw MigrationException::empty($this->version);
        }

        return $statements;
    }
}
