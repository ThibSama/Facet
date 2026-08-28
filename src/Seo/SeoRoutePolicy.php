<?php

declare(strict_types=1);

namespace Facet\Seo;

use Facet\Routing\HttpMethod;
use Facet\Routing\RouteCatalog;
use Facet\Routing\RouteDefinition;
use Facet\Routing\Visibility;

/** The exhaustive set of HTML routes search engines may index. */
final class SeoRoutePolicy
{
    private const INDEXABLE = [
        RouteCatalog::HOME,
        RouteCatalog::PROJECTS_INDEX,
        RouteCatalog::PROJECTS_SHOW,
        RouteCatalog::ABOUT,
        RouteCatalog::CONTACT,
    ];

    public static function isIndexable(RouteDefinition $route): bool
    {
        return in_array($route->name(), self::INDEXABLE, true)
            && $route->visibility() === Visibility::Public
            && $route->accepts(HttpMethod::Get);
    }
}
