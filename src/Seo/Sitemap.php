<?php

declare(strict_types=1);

namespace Facet\Seo;

use Facet\Content\Corpus;
use Facet\Routing\RouteCatalog;

/** Generates the sitemap from the versioned route contract and corpus slugs. */
final class Sitemap
{
    /** @return list<string> */
    public static function paths(Corpus $corpus): array
    {
        $paths = [];

        foreach (RouteCatalog::all() as $route) {
            if (!SeoRoutePolicy::isIndexable($route)) {
                continue;
            }

            if ($route->name() === RouteCatalog::PROJECTS_SHOW) {
                foreach ($corpus->projects() as $project) {
                    $paths[] = $route->toPath(['slug' => $project->slug()->value()]);
                }

                continue;
            }

            $paths[] = $route->toPath();
        }

        return $paths;
    }

    public static function render(SiteUrl $siteUrl, Corpus $corpus): string
    {
        $urls = '';

        foreach (self::paths($corpus) as $path) {
            $location = htmlspecialchars(
                $siteUrl->absolute($path),
                ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
            $urls .= '  <url><loc>' . $location . '</loc></url>' . "\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . $urls
            . '</urlset>' . "\n";
    }
}
