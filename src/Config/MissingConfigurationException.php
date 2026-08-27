<?php

declare(strict_types=1);

namespace Facet\Config;

use RuntimeException;

/**
 * Raised when a value that must never have a fallback is absent.
 */
final class MissingConfigurationException extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf(
            'Required configuration value "%s" is not set. Define it in the environment (see .env.example).',
            $key
        ));
    }
}
