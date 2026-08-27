<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Http;

use Facet\Http\MatchOutcome;
use Facet\Http\Request;
use Facet\Http\Router;
use Facet\Routing\HttpMethod;
use Facet\Routing\RouteCatalog;
use Facet\Support\Slug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Dispatching must reproduce the canonical contract exactly, and must keep the
 * three outcomes apart: unknown path, wrong method, hit.
 */
final class RouterTest extends TestCase
{
    private static function router(): Router
    {
        return Router::fromCatalog();
    }

    public function testTheRouterReadsTheCanonicalCatalogRatherThanItsOwnList(): void
    {
        self::assertCount(count(RouteCatalog::all()), self::router()->routes());
    }

    /**
     * Every declared route must be reachable at its declared path with its
     * declared method — the contract and the dispatcher cannot drift.
     */
    public function testEveryDeclaredRouteIsReachable(): void
    {
        foreach (RouteCatalog::all() as $name => $route) {
            $path = $route->toPath($route->isDynamic() ? ['slug' => 'kushim'] : []);

            foreach ($route->methods() as $method) {
                $match = self::router()->match(Request::create($method->value, $path));

                self::assertTrue($match->isMatch(), sprintf('%s %s must match', $method->value, $path));
                self::assertSame($name, $match->route()->name());
            }
        }
    }

    public function testAnUnknownPathIsNotFound(): void
    {
        foreach (['/nope', '/projects/kushim/extra', '/admin/messages/1', '/.env'] as $path) {
            $match = self::router()->match(Request::create('GET', $path));

            self::assertSame(MatchOutcome::NotFound, $match->outcome(), $path . ' must be a 404');
            self::assertTrue($match->isNotFound());
        }
    }

    public function testAKnownPathWithAnUnsupportedMethodIsMethodNotAllowed(): void
    {
        $match = self::router()->match(Request::create('POST', '/'));

        self::assertTrue($match->isMethodNotAllowed());
        self::assertSame(RouteCatalog::HOME, $match->route()->name());
        self::assertSame([HttpMethod::Get], $match->allowedMethods());
        self::assertSame('GET', $match->allowHeader());
    }

    public function testAMethodOutsideTheContractIsAlsoMethodNotAllowedOnAKnownPath(): void
    {
        // DELETE is not modelled at all; the path still exists, so the honest
        // answer is 405 with an Allow header, not 404.
        $match = self::router()->match(Request::create('DELETE', '/contact'));

        self::assertTrue($match->isMethodNotAllowed());
        self::assertSame('GET, POST', $match->allowHeader());
    }

    public function testAMethodOutsideTheContractOnAnUnknownPathIsStillNotFound(): void
    {
        self::assertTrue(self::router()->match(Request::create('DELETE', '/nope'))->isNotFound());
    }

    public function testPostIsDistinguishedFromGetOnTheSamePath(): void
    {
        $get = self::router()->match(Request::create('GET', '/contact'));
        $post = self::router()->match(Request::create('POST', '/contact'));

        self::assertTrue($get->isMatch());
        self::assertTrue($post->isMatch());
        self::assertSame(RouteCatalog::CONTACT, $post->route()->name());
    }

    public function testDynamicParametersAreExtracted(): void
    {
        $match = self::router()->match(Request::create('GET', '/projects/eszter-gyori'));

        self::assertTrue($match->isMatch());
        self::assertSame(RouteCatalog::PROJECTS_SHOW, $match->route()->name());
        self::assertSame(['slug' => 'eszter-gyori'], $match->parameters());
        self::assertSame('eszter-gyori', $match->parameter('slug'));
    }

    public function testAPercentEncodedSlugIsDecodedBeforeValidation(): void
    {
        $match = self::router()->match(Request::create('GET', '/projects/math%2Dl%2Dhome'));

        self::assertTrue($match->isMatch());
        self::assertSame('math-l-home', $match->parameter('slug'));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function malformedSlugs(): array
    {
        return [
            ['/projects/Kushim'],
            ['/projects/-kushim'],
            ['/projects/kushim-'],
            ['/projects/ku--shim'],
            ['/projects/ku_shim'],
            ['/projects/a'],
            ['/projects/' . str_repeat('a', Slug::MAX_LENGTH + 1)],
            ['/projects/%2E%2E%2F%2E%2E%2Fetc%2Fpasswd'],
            ['/projects/kushim%20'],
            ['/projects/a%2Fb'],
            ['/projects/kushim%00'],
        ];
    }

    /**
     * A value the canonical slug grammar rejects must never reach a handler.
     * Because routing validates through {@see Slug::PATTERN} itself, a URL that
     * routes is exactly a URL the corpus can resolve.
     */
    #[DataProvider('malformedSlugs')]
    public function testAMalformedSlugNeverMatches(string $path): void
    {
        self::assertTrue(
            self::router()->match(Request::create('GET', $path))->isNotFound(),
            $path . ' must not reach the projects.show handler'
        );
    }

    public function testStaticSegmentsWinOverDynamicOnesOnDifferentDepths(): void
    {
        $index = self::router()->match(Request::create('GET', '/projects'));
        $show = self::router()->match(Request::create('GET', '/projects/kushim'));

        self::assertSame(RouteCatalog::PROJECTS_INDEX, $index->route()->name());
        self::assertSame(RouteCatalog::PROJECTS_SHOW, $show->route()->name());
    }
}
