<?php

declare(strict_types=1);

namespace Facet\Navigation;

use Facet\Routing\RouteCatalog;
use Facet\Routing\RouteDefinition;

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
     * Sections the shell offers, in display order, mapped to their label.
     *
     * Only routes that are public *and* actually served belong here. Declared
     * but unbuilt routes stay out of the shell rather than advertising a page
     * that answers 501.
     *
     * @var array<string, string>
     */
    private const PRIMARY = [
        RouteCatalog::HOME => 'Home',
        RouteCatalog::PROJECTS_INDEX => 'Projects',
        RouteCatalog::ABOUT => 'About',
        RouteCatalog::CONTACT => 'Contact',
    ];

    /** The accessible name of the navigation landmark. */
    public const PRIMARY_LABEL = 'Primary';

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
     * @param string $path the canonical request path, e.g. "/projects/kushim"
     */
    public static function primary(string $path): self
    {
        $items = [];

        foreach (self::PRIMARY as $name => $label) {
            $route = RouteCatalog::get($name);

            $items[] = NavigationItem::create(
                $name,
                $label,
                $route->path(),
                self::covers($route, $path)
            );
        }

        return new self($items, self::PRIMARY_LABEL);
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
     * Home is exact — every path starts with "/", so a prefix rule would light
     * up the whole shell. Every other section also owns its descendants, which
     * is what makes "/projects/kushim" highlight Projects.
     */
    private static function covers(RouteDefinition $route, string $path): bool
    {
        $base = $route->path();

        if ($path === $base) {
            return true;
        }

        return $base !== '/' && str_starts_with($path, $base . '/');
    }
}
