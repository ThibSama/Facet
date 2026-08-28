<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Routing;

use Facet\Routing\DataSource;
use Facet\Routing\HttpMethod;
use Facet\Routing\RouteCatalog;
use Facet\Routing\RouteDefinition;
use Facet\Routing\Visibility;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The route contract, asserted as data rather than trusted as documentation.
 */
final class RouteCatalogTest extends TestCase
{
    /**
     * The canonical expectation for every required route.
     *
     * @return array<string, array{string, string, list<string>, Visibility, DataSource, string}>
     */
    public static function canonicalRoutes(): array
    {
        return [
            '/' => [
                RouteCatalog::HOME, '/', ['GET'],
                Visibility::Public, DataSource::ContentCorpus, 'page.home',
            ],
            '/projects' => [
                RouteCatalog::PROJECTS_INDEX, '/projects', ['GET'],
                Visibility::Public, DataSource::ContentCorpus, 'page.projects.index',
            ],
            '/projects/{slug}' => [
                RouteCatalog::PROJECTS_SHOW, '/projects/{slug}', ['GET'],
                Visibility::Public, DataSource::ContentCorpus, 'page.projects.show',
            ],
            '/about' => [
                RouteCatalog::ABOUT, '/about', ['GET'],
                Visibility::Public, DataSource::ContentCorpus, 'page.about',
            ],
            '/contact' => [
                RouteCatalog::CONTACT, '/contact', ['GET', 'POST'],
                Visibility::Public, DataSource::MessageStore, 'page.contact',
            ],
            '/login' => [
                RouteCatalog::LOGIN, '/login', ['GET', 'POST'],
                Visibility::Guest, DataSource::AuthSession, 'page.login',
            ],
            '/logout' => [
                RouteCatalog::LOGOUT, '/logout', ['POST'],
                Visibility::Authenticated, DataSource::AuthSession, 'page.logout',
            ],
            '/admin' => [
                RouteCatalog::ADMIN_DASHBOARD, '/admin', ['GET'],
                Visibility::Admin, DataSource::ContentCorpus, 'page.admin.dashboard',
            ],
            '/admin/messages' => [
                RouteCatalog::ADMIN_MESSAGES, '/admin/messages', ['GET', 'POST'],
                Visibility::Admin, DataSource::MessageStore, 'page.admin.messages',
            ],
            '/client' => [
                RouteCatalog::CLIENT_AREA, '/client', ['GET'],
                Visibility::Client, DataSource::AuthSession, 'page.client',
            ],
            '/sitemap.xml' => [
                RouteCatalog::SITEMAP, '/sitemap.xml', ['GET'],
                Visibility::Public, DataSource::ContentCorpus, 'technical.sitemap',
            ],
            '/robots.txt' => [
                RouteCatalog::ROBOTS, '/robots.txt', ['GET'],
                Visibility::Public, DataSource::None, 'technical.robots',
            ],
        ];
    }

    /**
     * @param list<string> $methods
     */
    #[DataProvider('canonicalRoutes')]
    public function testRouteDeclaresItsFullContract(
        string $name,
        string $path,
        array $methods,
        Visibility $visibility,
        DataSource $dataSource,
        string $template
    ): void {
        $route = RouteCatalog::get($name);

        self::assertSame($path, $route->path());
        self::assertSame($methods, $route->methodNames());
        self::assertSame($visibility, $route->visibility());
        self::assertSame($dataSource, $route->dataSource());
        self::assertSame($template, $route->template());
    }

    public function testCatalogContainsExactlyTheCanonicalRoutes(): void
    {
        $expected = array_map(
            static fn (array $row): string => (string) $row[0],
            array_values(self::canonicalRoutes())
        );

        sort($expected);
        $actual = RouteCatalog::names();
        sort($actual);

        self::assertSame($expected, $actual, 'The catalog must declare exactly the twelve canonical routes');
        self::assertCount(12, RouteCatalog::all());
    }

    public function testEveryRouteDeclaresMethodVisibilityDataSourceAndTemplate(): void
    {
        foreach (RouteCatalog::all() as $name => $route) {
            self::assertNotEmpty($route->methods(), $name . ' must declare at least one method');
            self::assertNotSame('', $route->template(), $name . ' must declare a logical template');
            self::assertMatchesRegularExpression('/^(page|technical)\./', $route->template(), $name . ' template must be a logical id');
            self::assertSame($name, $route->name());
        }
    }

    public function testPathsAreUnique(): void
    {
        $paths = array_map(
            static fn (RouteDefinition $route): string => $route->path(),
            array_values(RouteCatalog::all())
        );

        self::assertSame(array_values(array_unique($paths)), $paths);
    }

    public function testProtectedRoutesAreNotPubliclyReachable(): void
    {
        foreach ([
            RouteCatalog::ADMIN_DASHBOARD,
            RouteCatalog::ADMIN_MESSAGES,
            RouteCatalog::CLIENT_AREA,
            RouteCatalog::LOGOUT,
        ] as $name) {
            $route = RouteCatalog::get($name);

            self::assertTrue($route->visibility()->requiresAuthentication(), $name . ' must require auth');
            self::assertFalse($route->visibility()->isPubliclyReachable(), $name . ' must not be public');
        }

        $publicNames = array_map(
            static fn (RouteDefinition $r): string => $r->name(),
            RouteCatalog::publiclyReachable()
        );

        self::assertNotContains(RouteCatalog::ADMIN_DASHBOARD, $publicNames);
        self::assertNotContains(RouteCatalog::ADMIN_MESSAGES, $publicNames);
        self::assertNotContains(RouteCatalog::CLIENT_AREA, $publicNames);
        self::assertNotContains(RouteCatalog::LOGOUT, $publicNames);
    }

    /**
     * The client area is client-only, not "anyone signed in".
     *
     * Declared here because it is a contract change PORT-93 made deliberately:
     * before it, an administrator could reach /client simply by being
     * authenticated.
     */
    public function testTheClientAreaIsTheOnlyClientVisibility(): void
    {
        $client = array_map(
            static fn (RouteDefinition $r): string => $r->name(),
            RouteCatalog::withVisibility(Visibility::Client)
        );

        self::assertSame([RouteCatalog::CLIENT_AREA], $client);
        self::assertTrue(Visibility::Client->isRoleSpecific());
        self::assertTrue(Visibility::Admin->isRoleSpecific());
        self::assertFalse(Visibility::Authenticated->isRoleSpecific());
    }

    /**
     * Logout is a mutation, so it is a POST and only a POST. A GET /logout is
     * triggerable by any third-party page that can make a browser fetch a URL.
     */
    public function testLogoutIsPostOnlyAndRequiresAuthentication(): void
    {
        $logout = RouteCatalog::get(RouteCatalog::LOGOUT);

        self::assertSame(['POST'], $logout->methodNames());
        self::assertFalse($logout->accepts(HttpMethod::Get));
        self::assertTrue($logout->visibility()->requiresAuthentication());
    }

    public function testAdminRoutesAreTheOnlyAdminVisibility(): void
    {
        $admin = array_map(
            static fn (RouteDefinition $r): string => $r->name(),
            RouteCatalog::withVisibility(Visibility::Admin)
        );

        sort($admin);

        self::assertSame([RouteCatalog::ADMIN_DASHBOARD, RouteCatalog::ADMIN_MESSAGES], $admin);
    }

    /**
     * The full list of routes that accept a body, which is the list of routes
     * that can change something. It is asserted exhaustively rather than by
     * membership: a new POST route is a change to the security surface, and it
     * should not be possible to add one without this test noticing.
     */
    public function testOnlyDeclaredMutationRoutesAcceptPost(): void
    {
        $posting = [];

        foreach (RouteCatalog::all() as $name => $route) {
            if ($route->accepts(HttpMethod::Post)) {
                $posting[] = $name;
            }
        }

        sort($posting);

        self::assertSame([
            RouteCatalog::ADMIN_MESSAGES,
            RouteCatalog::CONTACT,
            RouteCatalog::LOGIN,
            RouteCatalog::LOGOUT,
        ], $posting);
    }

    public function testProjectShowIsTheOnlyDynamicRoute(): void
    {
        $dynamic = [];

        foreach (RouteCatalog::all() as $name => $route) {
            if ($route->isDynamic()) {
                $dynamic[] = $name;
            }
        }

        self::assertSame([RouteCatalog::PROJECTS_SHOW], $dynamic);
    }

    public function testUnknownRouteFailsWithAListOfKnownOnes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown route "nope"/');

        RouteCatalog::get('nope');
    }

    /**
     * The contract version is bumped when consumers must react — here, a route
     * was added, another's visibility narrowed, and the admin inbox gained its
     * status-mutation method.
     */
    public function testCatalogDeclaresAContractVersion(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', RouteCatalog::VERSION);
        self::assertSame('1.3.0', RouteCatalog::VERSION);
    }

    public function testCatalogIsIndependentOfRendering(): void
    {
        // A logical template id is a name, never a file path or an extension:
        // routing must not know how, or by which skin, a page is rendered.
        foreach (RouteCatalog::all() as $name => $route) {
            self::assertStringNotContainsString('/', $route->template(), $name);
            self::assertStringNotContainsString('.php', $route->template(), $name);
            self::assertStringNotContainsString('.html', $route->template(), $name);
        }
    }
}
