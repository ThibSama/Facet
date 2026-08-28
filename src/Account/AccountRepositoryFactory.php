<?php

declare(strict_types=1);

namespace Facet\Account;

use Facet\Config\Config;
use Facet\Database\Database;

/**
 * Chooses the repository a given configuration implies, without connecting.
 *
 * The connection stays lazy, so booting the application costs nothing and a GET
 * of a public page touches the database exactly as much as it did before this
 * checkpoint: not at all.
 *
 * The credentials are checked with {@see Config::has()} rather than read,
 * because reading a sensitive key that is absent is defined to throw — and
 * asking whether accounts are available must not be able to fail.
 */
final class AccountRepositoryFactory
{
    public static function fromConfig(Config $config): AccountRepository
    {
        foreach (['DB_DSN', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            if (!$config->has($key)) {
                return new UnavailableAccountRepository();
            }
        }

        return new DatabaseAccountRepository(Database::fromConfig($config));
    }
}
