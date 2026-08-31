<?php

declare(strict_types=1);

namespace Facet\Navigation;

use Facet\I18n\Locale;
use Facet\I18n\LocalizedRoutes;
use Facet\I18n\Translator;
use Facet\Routing\RouteCatalog;

/**
 * The primary navigation, derived from the route catalog.
 *
 * The catalog is the single declaration of what URLs exist, so navigation is
 * computed from it rather than transcribed next to it: a route whose path
 * changes moves the link with it, and a link can never point at a URL the
 * router does not serve. What this class adds on top is the part the catalog
 * has no opinion about — which public sections belong in the shell chrome, in
 * what order, and under what label.
 *
 * It is skin-agnostic by construction: it produces values, never markup, so
 * every skin renders the same navigation model its own way.
 */
final class Navigation
{
    /**
     * Sections the shell offers, in display order, mapped to their translation
     * key.
     *
     * Only routes that are public *and* actually served belong here. Declared
     * but unbuilt routes stay out of the shell rather than advertising a page
     * that answers 501. Since PORT-137 the value is a key rather than a word:
     * the shell's vocabulary is one entry in {@see \Facet\I18n\Translations},
     * so a section cannot be named in one language and linked in another.
     *
     * @var array<string, string>
     */
    private const PRIMARY = [
        RouteCatalog::HOME => 'nav.home',
        RouteCatalog::PROJECTS_INDEX => 'nav.projects',
        RouteCatalog::ABOUT => 'nav.about',
        RouteCatalog::CONTACT => 'nav.contact',
    ];

    /** The translation key for the accessible name of the landmark. */
    public const PRIMARY_LABEL_KEY = 'nav.label';

    /** @var list<NavigationItem> */
    private array $items;

    private string $label;

    /**
     * @param list<NavigationItem> $items
     */
    private function __construct(array $items, string $label)
    {
        $this->items = $items;
        $this->label = $label;
    }

    /**
     * Builds the primary navigation for the request currently being rendered.
     *
     * Every href carries the active locale, which is what stops a visitor
     * reading the English site from being handed a French page by the header:
     * navigation is built for a language, not merely rendered in one.
     *
     * @param string $path the canonical request path, e.g. "/en/projects/kushim"
     */
    public static function primary(Locale $locale, Translator $translator, string $path): self
    {
        $items = [];

        foreach (self::PRIMARY as $name => $labelKey) {
            $href = LocalizedRoutes::path($name, $locale);

            $items[] = NavigationItem::create(
                $name,
                $translator->text($labelKey),
                $href,
                self::covers($name, $href, $path)
            );
        }

        return new self($items, $translator->text(self::PRIMARY_LABEL_KEY));
    }

    /**
     * @return list<NavigationItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * The item marked current, if any. At most one ever is.
     */
    public function current(): ?NavigationItem
    {
        foreach ($this->items as $item) {
            if ($item->isCurrent()) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Whether a request path belongs to a route's section.
     *
     * Home is exact, and since PORT-137 that is load-bearing rather than
     * incidental: the localized home is "/fr", every other French page is
     * inside "/fr/", and a prefix rule would light up the whole shell on every
     * page of the site. Every other section still owns its descendants, which
     * is what makes "/en/projects/kushim" highlight Projects.
     */
    private static function covers(string $routeName, string $href, string $path): bool
    {
        if ($path === $href) {
            return true;
        }

        return $routeName !== RouteCatalog::HOME && str_starts_with($path, $href . '/');
    }
}
