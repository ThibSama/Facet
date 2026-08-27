<?php

declare(strict_types=1);

namespace Facet\Content\Exception;

/**
 * Raised when two canonical entries of the same kind claim one slug.
 *
 * Slugs are public URL identity, so a collision is a hard failure at corpus
 * construction rather than a last-one-wins overwrite.
 */
final class DuplicateSlugException extends ContentException
{
    public static function inCollection(string $collection, string $slug): self
    {
        return new self(sprintf(
            'Duplicate slug "%s" in collection "%s": canonical slugs must be unique and stable.',
            $slug,
            $collection
        ));
    }
}
