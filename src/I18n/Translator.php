<?php

declare(strict_types=1);

namespace Facet\I18n;

/**
 * One locale's view of the interface catalog.
 *
 * A translator is bound to a locale at construction and is the only way a
 * template or a handler obtains a string: there is no ambient "current locale",
 * so a page cannot render half of itself in the other language by forgetting to
 * pass one. It is a value object over {@see Translations} and holds no state of
 * its own.
 *
 * Substitution is positional-by-name: `{max}` in the entry is replaced by the
 * `max` key of the replacements array. A placeholder nothing was given for is
 * left in place rather than blanked, because a visible `{max}` is a defect the
 * completeness test catches and an empty gap is one nobody notices.
 */
final class Translator
{
    private Locale $locale;

    public function __construct(Locale $locale)
    {
        $this->locale = $locale;
    }

    public static function for(Locale $locale): self
    {
        return new self($locale);
    }

    public function locale(): Locale
    {
        return $this->locale;
    }

    /**
     * The catalog string for a key, with its placeholders filled.
     *
     * @param array<string, string|int> $replacements
     *
     * @throws MissingTranslationException when the catalog declares no such key
     */
    public function text(string $key, array $replacements = []): string
    {
        $entry = Translations::all()[$key] ?? null;

        if ($entry === null) {
            throw MissingTranslationException::forKey($key, $this->locale);
        }

        $text = $entry[$this->locale->value];

        if ($replacements === []) {
            return $text;
        }

        $search = [];
        $replace = [];

        foreach ($replacements as $name => $value) {
            $search[] = '{' . $name . '}';
            $replace[] = (string) $value;
        }

        return str_replace($search, $replace, $text);
    }
}
