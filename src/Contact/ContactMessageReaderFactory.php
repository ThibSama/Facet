<?php

declare(strict_types=1);

namespace Facet\Contact;

use Facet\Config\Config;
use Facet\Database\Database;

final class ContactMessageReaderFactory
{
    public static function fromConfig(Config $config): ContactMessageReader
    {
        foreach (['DB_DSN', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            if (!$config->has($key)) {
                return new UnavailableContactMessageReader();
            }
        }

        return new ContactMessageRepository(Database::fromConfig($config));
    }
}
