<?php

declare(strict_types=1);

namespace Facet\Navigation;

use Facet\I18n\Locale;
use Facet\I18n\LocalizedRoutes;
use Facet\I18n\Translator;
use Facet\Routing\RouteCatalog;
use Facet\Routing\RouteDefinition;
use Facet\Skin\Selection\SkinSelectionContext;

/**
 * The public language switch, as a model rather than as markup.
 *
 * Changing language is navigation to another canonical URL, so the switch is a
 * list of real links and every skin renders it as one: no script, no form, no
 * modal, and nothing that stops working when JavaScript does not run. This
 * class decides only *where* each link goes.
 *
 * The destination is the counterpart of the page being rendered whenever one
 * exists — `/fr/projects` from `/en/projects` — and the localized home when it
 * does not, which is the honest answer on a 404 or on any surface that is not a
 * paired public page. The query string is carried across, because it is the
 * same page being asked for in another language; a fragment is not, because a
 * fragment never reaches the server.
 */
final class LanguageSwitch
{
    /** @var list<LanguageSwitchItem> */
    private array $items;

    private string $label;

    /**
     * @param list<LanguageSwitchItem> $items
     */
    private function __construct(array $items, string $label)
    {
        $this->items = $items;
        $this->label = $label;
    }

    /**
     * The switch for one rendered page.
     *
     * @param array<string, string> $parameters the parameters the router matched
     * @param array<string, string> $query      the request's own query parameters
     */
    public static function create(
        Locale $current,
        Translator $translator,
        ?RouteDefinition $route,
        array $parameters = [],
        array $query = []
    ): self {
        $items = [];
        $queryString = self::carriedQuery($query);

        foreach (Locale::supported() as $locale) {
            $items[] = LanguageSwitchItem::create(
                $locale,
                LocalizedRoutes::withQuery(
                    self::destination($route, $parameters, $locale),
                    $queryString
                ),
                $locale === $current,
                $translator->text('language.switchTo', ['language' => $locale->endonym()])
            );
        }

        return new self($items, $translator->text('language.label'));
    }

    /**
     * The part of the request's query the switch carries across.
     *
     * Almost all of it: asking for the same page in another language should not
     * silently drop the parameters that said which page it was. The exception
     * is the skin override, which is a development-only way of selecting a
     * presentation and not part of the page's identity — carrying it would
     * write the name of an unselected skin into every public document, and a
     * skin is not something a language switch has any business choosing.
     *
     * @param array<string, string> $query
     */
    private static function carriedQuery(array $query): string
    {
        unset($query[SkinSelectionContext::QUERY_PARAMETER]);

        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, string> $parameters
     */
    private static function destination(?RouteDefinition $route, array $parameters, Locale $locale): string
    {
        if ($route !== null && in_array($route->name(), RouteCatalog::localizedNames(), true)) {
            return LocalizedRoutes::counterpartPath($route, $parameters, $locale);
        }

        return LocalizedRoutes::path(RouteCatalog::HOME, $locale);
    }

    /**
     * @return list<LanguageSwitchItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    /** The accessible name of the switch's own navigation landmark. */
    public function label(): string
    {
        return $this->label;
    }

    public function current(): LanguageSwitchItem
    {
        foreach ($this->items as $item) {
            if ($item->isCurrent()) {
                return $item;
            }
        }

        // Unreachable: the switch is always built from the locale being
        // rendered, which is one of the supported cases it lists.
        return $this->items[0];
    }
}
