<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Navigation;

use Facet\Navigation\Navigation;
use Facet\Navigation\NavigationItem;
use Facet\Routing\RouteCatalog;
use Facet\Routing\Visibility;
use PHPUnit\Framework\TestCase;

/**
 * The navigation model, which is where "which link is active" is decided once
 * for every skin.
 */
final class NavigationTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function hrefs(Navigation $navigation): array
    {
        return array_map(
            static fn (NavigationItem $item): string => $item->href(),
            $navigation->items()
        );
    }

    private static function currentLabel(Navigation $navigation): ?string
    {
        return $navigation->current()?->label();
    }

    public function testPrimaryNavigationExposesTheServedPublicSectionsInOrder(): void
    {
        $navigation = Navigation::primary('/');

        self::assertSame(['/', '/projects', '/about', '/contact'], self::hrefs($navigation));
        self::assertSame(
            ['Home', 'Projects', 'About', 'Contact'],
            array_map(static fn (NavigationItem $item): string => $item->label(), $navigation->items())
        );
        self::assertFalse($navigation->isEmpty());
        self::assertSame('Primary', $navigation->label());
    }

    /**
     * Every href is a path the catalog declares — navigation cannot drift into
     * pointing at a URL the router does not serve.
     */
    public function testEveryLinkComesFromTheRouteCatalog(): void
    {
        foreach (Navigation::primary('/')->items() as $item) {
            self::assertTrue(RouteCatalog::has($item->routeName()));

            $route = RouteCatalog::get($item->routeName());

            self::assertSame($route->path(), $item->href());
            self::assertSame(Visibility::Public, $route->visibility());
            self::assertFalse($route->isDynamic(), 'A shell link must not need parameters');
        }
    }

    /**
     * Routes that exist but are not public — or not served yet — stay out of
     * the shell rather than advertising a page that answers 401 or 501.
     */
    public function testProtectedRoutesAreNotAdvertisedInTheShell(): void
    {
        $names = array_map(
            static fn (NavigationItem $item): string => $item->routeName(),
            Navigation::primary('/')->items()
        );

        foreach ([RouteCatalog::LOGIN, RouteCatalog::ADMIN_DASHBOARD, RouteCatalog::ADMIN_MESSAGES, RouteCatalog::CLIENT_AREA] as $hidden) {
            self::assertNotContains($hidden, $names);
        }
    }

    public function testAnExactPathMarksExactlyOneItemCurrent(): void
    {
        foreach (['/' => 'Home', '/projects' => 'Projects', '/about' => 'About', '/contact' => 'Contact'] as $path => $label) {
            $navigation = Navigation::primary($path);

            $current = array_values(array_filter(
                $navigation->items(),
                static fn (NavigationItem $item): bool => $item->isCurrent()
            ));

            self::assertCount(1, $current, $path . ' must mark exactly one section');
            self::assertSame($label, $current[0]->label(), $path);
            self::assertSame('page', $current[0]->ariaCurrent());
        }
    }

    /**
     * The reason this class exists rather than a path comparison in a template:
     * a project detail URL is still inside Projects.
     */
    public function testANestedProjectUrlMarksItsSectionCurrent(): void
    {
        foreach (['/projects/kushim', '/projects/kushim/anything/deeper'] as $path) {
            $navigation = Navigation::primary($path);

            self::assertSame('Projects', self::currentLabel($navigation), $path);
        }
    }

    /**
     * Home is exact on purpose. Every path begins with "/", so a prefix rule
     * would report the whole shell as current on every page.
     */
    public function testHomeIsNotCurrentForEveryOtherPath(): void
    {
        foreach (['/projects', '/projects/kushim', '/about', '/contact'] as $path) {
            $home = Navigation::primary($path)->items()[0];

            self::assertSame('Home', $home->label());
            self::assertFalse($home->isCurrent(), $path . ' must not mark Home current');
            self::assertNull($home->ariaCurrent());
        }
    }

    /**
     * A sibling whose path merely shares a prefix is a different section.
     */
    public function testAPrefixThatIsNotASegmentBoundaryIsNotCurrent(): void
    {
        self::assertNull(self::currentLabel(Navigation::primary('/projectsomething')));
        self::assertNull(self::currentLabel(Navigation::primary('/aboutus')));
    }

    /**
     * An unrouted path still yields a complete, usable navigation — which is
     * what a 404 page renders.
     */
    public function testAnUnknownPathStillProducesTheFullNavigation(): void
    {
        $navigation = Navigation::primary('/definitely-not-a-page');

        self::assertCount(4, $navigation->items());
        self::assertNull($navigation->current());
    }
}
