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
            '/admin' => [
                RouteCatalog::ADMIN_DASHBOARD, '/admin', ['GET'],
                Visibility::Admin, DataSource::ContentCorpus, 'page.admin.dashboard',
            ],
            '/admin/messages' => [
                RouteCatalog::ADMIN_MESSAGES, '/admin/messages', ['GET'],
                Visibility::Admin, DataSource::MessageStore, 'page.admin.messages',
            ],
            '/client' => [
                RouteCatalog::CLIENT_AREA, '/client', ['GET'],
                Visibility::Authenticated, DataSource::AuthSession, 'page.client',
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

        self::assertSame($expected, $actual, 'The catalog must declare exactly the nine canonical routes');
        self::assertCount(9, RouteCatalog::all());
    }

    public function testEveryRouteDeclaresMethodVisibilityDataSourceAndTemplate(): void
    {
        foreach (RouteCatalog::all() as $name => $route) {
            self::assertNotEmpty($route->methods(), $name . ' must declare at least one method');
            self::assertNotSame('', $route->template(), $name . ' must declare a logical template');
            self::assertStringStartsWith('page.', $route->template(), $name . ' template must be a logical id');
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
        foreach ([RouteCatalog::ADMIN_DASHBOARD, RouteCatalog::ADMIN_MESSAGES, RouteCatalog::CLIENT_AREA] as $name) {
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

    public function testOnlyContactAndLoginAcceptPost(): void
    {
        $posting = [];

        foreach (RouteCatalog::all() as $name => $route) {
            if ($route->accepts(HttpMethod::Post)) {
                $posting[] = $name;
            }
        }

        sort($posting);

        self::assertSame([RouteCatalog::CONTACT, RouteCatalog::LOGIN], $posting);
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

    public function testCatalogDeclaresAContractVersion(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', RouteCatalog::VERSION);
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
