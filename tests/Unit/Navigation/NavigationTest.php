<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Navigation;

use Facet\I18n\Locale;
use Facet\I18n\LocalizedRoutes;
use Facet\I18n\Translator;
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

    /** The navigation of one page, in one language. */
    private static function navigation(string $path, Locale $locale = Locale::Fr): Navigation
    {
        return Navigation::primary($locale, new Translator($locale), $path);
    }

    public function testPrimaryNavigationExposesTheServedPublicSectionsInOrder(): void
    {
        $french = self::navigation('/fr');

        self::assertSame(['/fr', '/fr/projects', '/fr/about', '/fr/contact'], self::hrefs($french));
        self::assertSame(
            ['Accueil', 'Projets', 'À propos', 'Contact'],
            array_map(static fn (NavigationItem $item): string => $item->label(), $french->items())
        );
        self::assertFalse($french->isEmpty());
        self::assertSame('Navigation principale', $french->label());

        // The same sections, the same order, the other language — links
        // included. A shell that named its sections in English and linked them
        // in French would be the exact defect PORT-137 exists to remove.
        $english = self::navigation('/en', Locale::En);

        self::assertSame(['/en', '/en/projects', '/en/about', '/en/contact'], self::hrefs($english));
        self::assertSame(
            ['Home', 'Projects', 'About', 'Contact'],
            array_map(static fn (NavigationItem $item): string => $item->label(), $english->items())
        );
        self::assertSame('Primary', $english->label());
    }

    /**
     * Every href is a path the catalog declares — navigation cannot drift into
     * pointing at a URL the router does not serve.
     */
    public function testEveryLinkComesFromTheRouteCatalog(): void
    {
        foreach (Locale::supported() as $locale) {
            foreach (self::navigation('/', $locale)->items() as $item) {
                self::assertTrue(RouteCatalog::has($item->routeName()));

                $route = RouteCatalog::get($item->routeName());

                self::assertSame(LocalizedRoutes::path($route->name(), $locale), $item->href());
                self::assertSame(Visibility::Public, $route->visibility());
                self::assertSame(
                    ['locale'],
                    array_map(
                        static fn (\Facet\Routing\RouteParameter $p): string => $p->name(),
                        $route->parameters()
                    ),
                    'A shell link may need the language and nothing else'
                );
            }
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
            self::navigation('/fr')->items()
        );

        foreach ([RouteCatalog::LOGIN, RouteCatalog::ADMIN_DASHBOARD, RouteCatalog::ADMIN_MESSAGES, RouteCatalog::CLIENT_AREA] as $hidden) {
            self::assertNotContains($hidden, $names);
        }
    }

    public function testAnExactPathMarksExactlyOneItemCurrent(): void
    {
        $expected = [
            '/fr' => 'Accueil',
            '/fr/projects' => 'Projets',
            '/fr/about' => 'À propos',
            '/fr/contact' => 'Contact',
        ];

        foreach ($expected as $path => $label) {
            $navigation = self::navigation($path);

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
        foreach (['/fr/projects/kushim', '/fr/projects/kushim/anything/deeper'] as $path) {
            self::assertSame('Projets', self::currentLabel(self::navigation($path)), $path);
        }

        foreach (['/en/projects/kushim', '/en/projects/kushim/anything/deeper'] as $path) {
            self::assertSame('Projects', self::currentLabel(self::navigation($path, Locale::En)), $path);
        }
    }

    /**
     * Home is exact on purpose. Every path begins with "/", so a prefix rule
     * would report the whole shell as current on every page.
     */
    public function testHomeIsNotCurrentForEveryOtherPath(): void
    {
        foreach (['/fr/projects', '/fr/projects/kushim', '/fr/about', '/fr/contact'] as $path) {
            $home = self::navigation($path)->items()[0];

            self::assertSame('Accueil', $home->label());
            self::assertFalse($home->isCurrent(), $path . ' must not mark Home current');
            self::assertNull($home->ariaCurrent());
        }
    }

    /**
     * A sibling whose path merely shares a prefix is a different section.
     */
    public function testAPrefixThatIsNotASegmentBoundaryIsNotCurrent(): void
    {
        self::assertNull(self::currentLabel(self::navigation('/fr/projectsomething')));
        self::assertNull(self::currentLabel(self::navigation('/fr/aboutus')));

        // And the locale root itself is exact: "/french-thing" is not "/fr".
        self::assertNull(self::currentLabel(self::navigation('/french-thing')));
    }

    /**
     * An unrouted path still yields a complete, usable navigation — which is
     * what a 404 page renders.
     */
    public function testAnUnknownPathStillProducesTheFullNavigation(): void
    {
        $navigation = self::navigation('/definitely-not-a-page');

        self::assertCount(4, $navigation->items());
        self::assertNull($navigation->current());
    }
}
