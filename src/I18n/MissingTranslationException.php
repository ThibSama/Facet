<?php

declare(strict_types=1);

namespace Facet\I18n;

use RuntimeException;

/**
 * A string the interface asked for and the catalog does not declare.
 *
 * It is always a programming error, never a content gap: the catalog holds both
 * languages of every entry in one array, so a key is either declared for both
 * or declared for neither. Raising rather than degrading is the deliberate
 * half of the missing-translation policy — the alternative is a page that
 * prints `home.selectedWork` at a visitor, or a French page that silently grows
 * an English heading, and both are worse than an honest failure the error
 * presenter renders in one place.
 */
final class MissingTranslationException extends RuntimeException
{
    public static function forKey(string $key, Locale $locale): self
    {
        return new self(sprintf(
            'No translation is declared for "%s" (asked for locale "%s").',
            $key,
            $locale->value
        ));
    }
}
