<?php

declare(strict_types=1);

namespace Facet\Navigation;

use Facet\I18n\Locale;

/**
 * One language a visitor may switch to.
 *
 * It carries both the compact mark a header shows — "FR", "EN" — and a full
 * accessible name, because two letters are a good visual label and a poor
 * spoken one. The language's own name is used in that accessible name rather
 * than a translation of it: somebody looking for English is looking for the
 * word "English".
 */
final class LanguageSwitchItem
{
    private Locale $locale;

    private string $href;

    private bool $current;

    private string $accessibleLabel;

    private function __construct(Locale $locale, string $href, bool $current, string $accessibleLabel)
    {
        $this->locale = $locale;
        $this->href = $href;
        $this->current = $current;
        $this->accessibleLabel = $accessibleLabel;
    }

    public static function create(Locale $locale, string $href, bool $current, string $accessibleLabel): self
    {
        return new self($locale, $href, $current, $accessibleLabel);
    }

    public function locale(): Locale
    {
        return $this->locale;
    }

    /** The two-letter mark, e.g. "EN". */
    public function label(): string
    {
        return $this->locale->shortLabel();
    }

    /** The language's own name for itself, e.g. "Français". */
    public function endonym(): string
    {
        return $this->locale->endonym();
    }

    public function href(): string
    {
        return $this->href;
    }

    public function isCurrent(): bool
    {
        return $this->current;
    }

    public function accessibleLabel(): string
    {
        return $this->accessibleLabel;
    }

    /** The `lang` attribute a link to another language must carry. */
    public function hrefLang(): string
    {
        return $this->locale->htmlLang();
    }

    /**
     * `aria-current="true"` on the language in effect, and null otherwise.
     *
     * "true" rather than "page": the link does point at the current page, but
     * what the switch states is which *language* is in effect, and `page` is
     * already spoken by the primary navigation for the section.
     */
    public function ariaCurrent(): ?string
    {
        return $this->current ? 'true' : null;
    }
}
