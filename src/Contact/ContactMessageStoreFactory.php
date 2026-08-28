<?php

declare(strict_types=1);

namespace Facet\Contact;

use Facet\Config\Config;
use Facet\Database\Database;

/**
 * Chooses the store a given configuration implies, without connecting.
 *
 * The connection stays lazy — {@see Database} does not dial until a query runs
 * — so booting the application costs nothing, and a GET of the contact page
 * touches the database exactly as much as it did before this checkpoint: not at
 * all.
 *
 * The three credentials are checked with {@see Config::has()} rather than read,
 * because reading a sensitive key that is absent is defined to throw. Asking
 * whether a store is configured must not be able to fail.
 */
final class ContactMessageStoreFactory
{
    public static function fromConfig(Config $config): ContactMessageStore
    {
        foreach (['DB_DSN', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            if (!$config->has($key)) {
                return new UnavailableContactMessageStore();
            }
        }

        return new ContactMessageRepository(Database::fromConfig($config));
    }
}
