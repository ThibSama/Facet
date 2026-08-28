<?php

declare(strict_types=1);

namespace Facet\Database;

use Facet\Config\Config;
use SensitiveParameter;

/**
 * The three values needed to reach the database, and nothing else.
 *
 * Every one of them is read from configuration; none has a fallback. A
 * database boundary that can invent its own credentials is a boundary that
 * silently connects somewhere unintended, so an absent value is a hard failure
 * here rather than a default.
 *
 * The object is also responsible for keeping its own contents out of any text
 * the application emits — see {@see redact()} and {@see __debugInfo()}.
 */
final class DatabaseCredentials
{
    public const DSN_KEY = 'DB_DSN';
    public const USERNAME_KEY = 'DB_USERNAME';
    public const PASSWORD_KEY = 'DB_PASSWORD';

    private const REDACTED = '<redacted>';

    /**
     * MariaDB speaks the MySQL protocol and uses PDO's `mysql` driver. The
     * driver is pinned deliberately: it is what stops a misconfigured
     * environment from silently pointing the suite at SQLite, where the schema
     * this project relies on would not be exercised at all.
     */
    private const REQUIRED_DRIVER = 'mysql';

    private const REQUIRED_CHARSET = 'utf8mb4';

    private string $dsn;

    private string $username;

    private string $password;

    private function __construct(
        string $dsn,
        string $username,
        #[SensitiveParameter] string $password
    ) {
        $this->dsn = $dsn;
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * @throws \Facet\Config\MissingConfigurationException when a value is absent
     * @throws DatabaseException when the DSN is not a utf8mb4 MySQL/MariaDB DSN
     */
    public static function fromConfig(Config $config): self
    {
        return self::create(
            $config->require(self::DSN_KEY),
            $config->require(self::USERNAME_KEY),
            $config->require(self::PASSWORD_KEY)
        );
    }

    /**
     * @throws DatabaseException when the DSN is not a utf8mb4 MySQL/MariaDB DSN
     */
    public static function create(
        string $dsn,
        string $username,
        #[SensitiveParameter] string $password
    ): self {
        return new self(self::normaliseDsn($dsn), $username, $password);
    }

    public function dsn(): string
    {
        return $this->dsn;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function password(): string
    {
        return $this->password;
    }

    /**
     * Remove every credential this object holds from arbitrary driver text.
     *
     * Longest value first, so that a password which happens to contain the
     * username cannot leave a fragment behind.
     */
    public function redact(string $text): string
    {
        $secrets = array_filter([$this->password, $this->username, $this->dsn], static fn (string $v): bool => $v !== '');

        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return str_replace($secrets, self::REDACTED, $text);
    }

    /**
     * Keeps `var_dump()` of a connection from printing the password.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'dsn' => self::REDACTED,
            'username' => self::REDACTED,
            'password' => self::REDACTED,
        ];
    }

    /**
     * Force the connection charset to be stated, and to be utf8mb4.
     *
     * An unset charset leaves the connection on the server's default, which is
     * how four-byte characters quietly become `?` on the way in. Rather than
     * hope the environment is right, an absent charset is added and a
     * conflicting one is rejected.
     *
     * @throws DatabaseException
     */
    private static function normaliseDsn(string $dsn): string
    {
        $dsn = trim($dsn);

        if ($dsn === '') {
            throw DatabaseException::misconfigured('the DSN is empty');
        }

        if (!str_starts_with($dsn, self::REQUIRED_DRIVER . ':')) {
            throw DatabaseException::misconfigured(sprintf(
                'the DSN must use the "%s" driver (MariaDB), got "%s"',
                self::REQUIRED_DRIVER,
                self::driverOf($dsn)
            ));
        }

        $charset = self::parameterOf($dsn, 'charset');

        if ($charset === null) {
            return $dsn . ';charset=' . self::REQUIRED_CHARSET;
        }

        if (strtolower($charset) !== self::REQUIRED_CHARSET) {
            throw DatabaseException::misconfigured(sprintf(
                'the DSN charset must be %s, got "%s"',
                self::REQUIRED_CHARSET,
                $charset
            ));
        }

        return $dsn;
    }

    private static function driverOf(string $dsn): string
    {
        $colon = strpos($dsn, ':');

        return $colon === false ? $dsn : substr($dsn, 0, $colon);
    }

    private static function parameterOf(string $dsn, string $name): ?string
    {
        $colon = strpos($dsn, ':');
        $body = $colon === false ? '' : substr($dsn, $colon + 1);

        foreach (explode(';', $body) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $pair, 2);

            if (strtolower(trim($key)) === $name) {
                return trim($value);
            }
        }

        return null;
    }
}
