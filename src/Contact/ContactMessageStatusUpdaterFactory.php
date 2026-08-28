<?php

declare(strict_types=1);

namespace Facet\Contact;

use Facet\Config\Config;
use Facet\Database\Database;

final class ContactMessageStatusUpdaterFactory
{
    public static function fromConfig(Config $config): ContactMessageStatusUpdater
    {
        foreach (['DB_DSN', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            if (!$config->has($key)) {
                return new UnavailableContactMessageStatusUpdater();
            }
        }

        return new ContactMessageRepository(Database::fromConfig($config));
    }
}
