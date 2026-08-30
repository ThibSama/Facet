<?php

declare(strict_types=1);

namespace Facet\Config;

use RuntimeException;

/**
 * Raised when a value that must never have a fallback is absent.
 *
 * The message names the key and every place it could have come from, and never
 * the value of anything: a diagnostic that echoed neighbouring configuration
 * would turn a misconfiguration into a disclosure. What the reader needs is
 * where to put the value, not what is already there.
 */
final class MissingConfigurationException extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf(
            'Required configuration value "%s" is not set. Resolution order is: '
            . 'process environment, then %s (optional, local development only), then .env. '
            . 'Copy .env.example to .env and set %s there — see the Configuration section of README.md. '
            . 'No configured value is printed by this diagnostic.',
            $key,
            Config::LOCAL_OVERRIDE_FILE,
            $key
        ));
    }

    /**
     * The test suite's own credentials, which the application may not read.
     */
    public static function forTestOnlyKey(string $key): self
    {
        return new self(sprintf(
            'Configuration value "%s" is owned by the test suite (.env.testing) and is never '
            . 'readable from application configuration. Application database access is configured '
            . 'through DB_DSN / DB_USERNAME / DB_PASSWORD; see .env.testing.example for the test keys.',
            $key
        ));
    }
}
