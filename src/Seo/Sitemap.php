<?php

declare(strict_types=1);

namespace Facet\Seo;

use Facet\Content\Corpus;
use Facet\I18n\Locale;
use Facet\I18n\LocalizedRoutes;
use Facet\Routing\RouteCatalog;

/**
 * Generates the sitemap from the versioned route contract and corpus slugs.
 *
 * Since PORT-137 every indexable page exists in two languages, so the sitemap
 * lists both and states the relationship between them: each `<url>` carries the
 * `xhtml:link` alternates of the page it names, including `x-default`. What it
 * deliberately never lists is an unprefixed entry route — those redirect and
 * are not canonical destinations, so advertising them would be advertising
 * duplicate content.
 *
 * The pairs come from {@see LocalizedRoutes} rather than from string
 * concatenation here, which is what keeps the sitemap, the `hreflang` block and
 * the language switch pointing at exactly the same URLs.
 */
final class Sitemap
{
    /**
     * Every canonical localized path, grouped by the page it belongs to.
     *
     * @return list<array<string, string>> locale value => path, one entry per page
     */
    public static function pages(Corpus $corpus): array
    {
        $pages = [];

        foreach (RouteCatalog::all() as $route) {
            if (!SeoRoutePolicy::isIndexable($route)) {
                continue;
            }

            if ($route->name() === RouteCatalog::PROJECTS_SHOW) {
                foreach ($corpus->projects() as $project) {
                    $pages[] = LocalizedRoutes::allPaths(
                        $route->name(),
                        ['slug' => $project->slug()->value()]
                    );
                }

                continue;
            }

            $pages[] = LocalizedRoutes::allPaths($route->name());
        }

        return $pages;
    }

    /** @return list<string> */
    public static function paths(Corpus $corpus): array
    {
        $paths = [];

        foreach (self::pages($corpus) as $page) {
            foreach ($page as $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    public static function render(SiteUrl $siteUrl, Corpus $corpus): string
    {
        $urls = '';

        foreach (self::pages($corpus) as $page) {
            $alternates = '';

            foreach ($page as $localeValue => $path) {
                $alternates .= '    <xhtml:link rel="alternate" hreflang="' . self::escape($localeValue)
                    . '" href="' . self::escape($siteUrl->absolute($path)) . '"/>' . "\n";
            }

            $alternates .= '    <xhtml:link rel="alternate" hreflang="x-default" href="'
                . self::escape($siteUrl->absolute($page[Locale::default()->value])) . '"/>' . "\n";

            foreach ($page as $path) {
                $urls .= '  <url>' . "\n"
                    . '    <loc>' . self::escape($siteUrl->absolute($path)) . '</loc>' . "\n"
                    . $alternates
                    . '  </url>' . "\n";
            }
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n"
            . $urls
            . '</urlset>' . "\n";
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
