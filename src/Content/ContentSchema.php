<?php

declare(strict_types=1);

namespace Facet\Content;

use Facet\Content\Exception\InvalidContentException;
use Facet\Content\Exception\UnsupportedSchemaVersionException;

/**
 * The schema boundary between stored content and the application.
 *
 * Every content file declares the schema it was written against. Copy and
 * media may change freely inside a schema version; changing the *shape* of
 * content requires bumping the major version here, which makes the break
 * explicit and testable instead of silent.
 */
final class ContentSchema
{
    public const VERSION = '1.0.0';

    /**
     * @throws InvalidContentException            when the declaration is absent or malformed
     * @throws UnsupportedSchemaVersionException  when the major version differs
     */
    public static function assertSupported(mixed $declared, string $source): void
    {
        if (!is_string($declared) || $declared === '') {
            throw InvalidContentException::missingKey($source, 'schemaVersion');
        }

        if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $declared, $matches) !== 1) {
            throw InvalidContentException::because(
                $source,
                sprintf('schemaVersion "%s" is not a semantic version', $declared)
            );
        }

        $supportedMajor = explode('.', self::VERSION)[0];

        if ($matches[1] !== $supportedMajor) {
            throw UnsupportedSchemaVersionException::for($source, $declared, self::VERSION);
        }
    }
}
