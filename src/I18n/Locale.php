<?php

declare(strict_types=1);

namespace Facet\I18n;

/**
 * The public site's supported languages, and the only two that exist.
 *
 * The set is closed on purpose. A locale is a URL segment, a `<html lang>`, a
 * cookie value and a catalog column all at once, so "any BCP 47 tag" would be
 * four different open sets rather than one closed one. Adding a third language
 * is a deliberate edit here, and every completeness test in the suite reads
 * `cases()` rather than a list of its own.
 *
 * French is the default because the canonical corpus is written in French: the
 * English site is a translation of it, never the other way round, which is also
 * why `x-default` points at the French URL.
 */
enum Locale: string
{
    case Fr = 'fr';
    case En = 'en';

    /**
     * The locale an unprefixed request falls back to when nothing else decides.
     */
    public static function default(): self
    {
        return self::Fr;
    }

    /**
     * The locale for an exact URL segment or cookie value, or null.
     *
     * Deliberately exact rather than forgiving: "FR", "fr-FR" and " fr " are
     * not URL segments this site serves, and repairing them here would create
     * a second spelling of a canonical URL.
     */
    public static function fromSegment(string $value): ?self
    {
        return self::tryFrom($value);
    }

    /**
     * The locale for a BCP 47 language tag, or null when unsupported.
     *
     * Only the primary subtag is read, so `fr-FR`, `fr-CA` and `FR` all resolve
     * to French. That is the whole of the matching this site needs: it has no
     * regional variants, so a region can only ever be noise.
     */
    public static function fromLanguageTag(string $tag): ?self
    {
        $primary = strtolower(explode('-', trim($tag), 2)[0]);

        return self::tryFrom($primary);
    }

    /** The value of `<html lang>` for a page in this locale. */
    public function htmlLang(): string
    {
        return $this->value;
    }

    /** The URL path segment that makes this locale canonical, e.g. "/fr". */
    public function segment(): string
    {
        return $this->value;
    }

    /**
     * The Open Graph locale, which wants a full territory-qualified tag.
     *
     * The territories below are the ones the site is written for and are stated
     * once here rather than being assembled from a language and a guess.
     */
    public function openGraphLocale(): string
    {
        return match ($this) {
            self::Fr => 'fr_FR',
            self::En => 'en_US',
        };
    }

    /** The other locale. With exactly two, "the other" is total. */
    public function counterpart(): self
    {
        return match ($this) {
            self::Fr => self::En,
            self::En => self::Fr,
        };
    }

    /**
     * The label a language switch shows for this locale.
     *
     * It is the language's own name for itself, not a translation of it: a
     * switch is read by somebody who wants the *other* language, so "English"
     * on a French page is the useful string and "Anglais" is not.
     */
    public function endonym(): string
    {
        return match ($this) {
            self::Fr => 'Français',
            self::En => 'English',
        };
    }

    /** The two-letter mark the compact switch shows. */
    public function shortLabel(): string
    {
        return strtoupper($this->value);
    }

    /**
     * @return list<self>
     */
    public static function supported(): array
    {
        return self::cases();
    }
}
