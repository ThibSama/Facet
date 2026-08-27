<?php

declare(strict_types=1);

namespace Facet\Content\Exception;

/**
 * Raised when a content file declares a schema this build cannot read.
 */
final class UnsupportedSchemaVersionException extends ContentException
{
    public static function for(string $source, string $found, string $supported): self
    {
        return new self(sprintf(
            'Content source "%s" declares schema version "%s"; this build supports "%s".',
            $source,
            $found,
            $supported
        ));
    }
}
