<?php

declare(strict_types=1);

namespace Facet\I18n;

use Facet\Routing\RouteCatalog;
use Facet\Routing\RouteDefinition;

/**
 * Builds the canonical URL of a public page in a given language.
 *
 * Every localized public route declares a `{locale}` segment, so a URL is never
 * assembled by gluing a prefix onto a path: it goes through the route's own
 * {@see RouteDefinition::toPath()}, which validates the locale segment through
 * the same parameter the router matched it with. A link this class produces is
 * therefore a link the router accepts, by construction.
 *
 * This is also the one place that knows a page has a counterpart in the other
 * language. The language switch, the `hreflang` block and the sitemap all read
 * it from here rather than each deriving "the same page, other language" from a
 * request path.
 */
final class LocalizedRoutes
{
    /**
     * The canonical path of a localized route in one language.
     *
     * @param array<string, string> $parameters route parameters other than the locale
     */
    public static function path(string $routeName, Locale $locale, array $parameters = []): string
    {
        return RouteCatalog::get($routeName)->toPath(
            ['locale' => $locale->value] + $parameters
        );
    }

    /**
     * The same page in the other language, as a path.
     *
     * @param array<string, string> $parameters the parameters the router matched, locale included
     */
    public static function counterpartPath(RouteDefinition $route, array $parameters, Locale $target): string
    {
        unset($parameters['locale']);

        return self::path($route->name(), $target, $parameters);
    }

    /**
     * Every language's path for one page, keyed by locale value.
     *
     * @param array<string, string> $parameters route parameters other than the locale
     *
     * @return array<string, string>
     */
    public static function allPaths(string $routeName, array $parameters = []): array
    {
        $paths = [];

        foreach (Locale::supported() as $locale) {
            $paths[$locale->value] = self::path($routeName, $locale, $parameters);
        }

        return $paths;
    }

    /**
     * Appends a query string to a path, when there is one worth keeping.
     *
     * A language switch keeps the query because it is the same page being
     * asked for in another language — dropping `?id=3` would land the visitor
     * somewhere they did not ask to go. The fragment is deliberately absent:
     * a fragment never reaches the server, so PHP cannot preserve one and
     * pretending otherwise would be a link that silently loses it.
     */
    public static function withQuery(string $path, string $queryString): string
    {
        return $queryString === '' ? $path : $path . '?' . $queryString;
    }
}
