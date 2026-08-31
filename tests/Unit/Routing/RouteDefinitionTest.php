<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Routing;

use Facet\I18n\Locale;
use Facet\Routing\DataSource;
use Facet\Routing\HttpMethod;
use Facet\Routing\RouteCatalog;
use Facet\Routing\RouteDefinition;
use Facet\Routing\RouteParameter;
use Facet\Routing\Visibility;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RouteDefinitionTest extends TestCase
{
    public function testDynamicPathIsBuiltFromParameters(): void
    {
        $route = RouteCatalog::get(RouteCatalog::PROJECTS_SHOW);

        self::assertSame('/fr/projects/kushim', $route->toPath(['locale' => 'fr', 'slug' => 'kushim']));
        self::assertSame(
            '/en/projects/portfolio-2024',
            $route->toPath(['locale' => 'en', 'slug' => 'portfolio-2024'])
        );
    }

    public function testMissingParameterIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/requires parameter "slug"/');

        RouteCatalog::get(RouteCatalog::PROJECTS_SHOW)->toPath(['locale' => 'fr']);
    }

    public function testMalformedSlugParameterIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not acceptable for parameter "slug"/');

        RouteCatalog::get(RouteCatalog::PROJECTS_SHOW)->toPath(['locale' => 'fr', 'slug' => 'Not A Slug']);
    }

    public function testSlugParameterUsesTheCanonicalSlugGrammar(): void
    {
        $parameter = RouteParameter::slug();

        self::assertTrue($parameter->accepts('kushim'));
        self::assertTrue($parameter->accepts('portfolio-2024'));
        self::assertFalse($parameter->accepts('Kushim'));
        self::assertFalse($parameter->accepts('kushim--api'));
        self::assertFalse($parameter->accepts('kushim/api'));
        self::assertFalse($parameter->accepts(''));
        self::assertSame('{slug}', $parameter->placeholder());
    }

    /**
     * The language segment is a route parameter like any other, and it is
     * validated by the same closed set the application renders in — which is
     * what makes an unsupported language a routing miss rather than a page.
     */
    public function testLocaleParameterAcceptsExactlyTheSupportedLanguages(): void
    {
        $parameter = RouteParameter::locale();

        foreach (Locale::supported() as $locale) {
            self::assertTrue($parameter->accepts($locale->value));
        }

        foreach (['de', 'es', 'FR', 'EN', 'fr-FR', 'f', 'frr', '', 'xx'] as $rejected) {
            self::assertFalse($parameter->accepts($rejected), $rejected . ' is not a language this site serves');
        }

        self::assertSame('{locale}', $parameter->placeholder());
    }

    public function testAnUnsupportedLocaleCannotBeBuiltIntoAPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not acceptable for parameter "locale"/');

        RouteCatalog::get(RouteCatalog::ABOUT)->toPath(['locale' => 'de']);
    }

    public function testUndeclaredPlaceholderIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/declares parameters \[\] but its path uses \[slug\]/');

        RouteDefinition::define(
            'broken',
            '/broken/{slug}',
            [HttpMethod::Get],
            Visibility::Public,
            DataSource::ContentCorpus,
            'page.broken'
        );
    }

    public function testUnusedParameterIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RouteDefinition::define(
            'broken',
            '/broken',
            [HttpMethod::Get],
            Visibility::Public,
            DataSource::ContentCorpus,
            'page.broken',
            [RouteParameter::slug()]
        );
    }

    public function testRelativePathIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must start with "\/"/');

        RouteDefinition::define(
            'broken',
            'broken',
            [HttpMethod::Get],
            Visibility::Public,
            DataSource::ContentCorpus,
            'page.broken'
        );
    }

    public function testEmptyTemplateIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must declare a logical template/');

        RouteDefinition::define(
            'broken',
            '/broken',
            [HttpMethod::Get],
            Visibility::Public,
            DataSource::ContentCorpus,
            ''
        );
    }

    public function testMethodAcceptance(): void
    {
        $contact = RouteCatalog::get(RouteCatalog::CONTACT);
        $about = RouteCatalog::get(RouteCatalog::ABOUT);

        self::assertTrue($contact->accepts(HttpMethod::Get));
        self::assertTrue($contact->accepts(HttpMethod::Post));
        self::assertTrue($about->accepts(HttpMethod::Get));
        self::assertFalse($about->accepts(HttpMethod::Post));
        self::assertTrue(HttpMethod::Get->isSafe());
        self::assertFalse(HttpMethod::Post->isSafe());
    }

    public function testStaticRouteBuildsItsOwnPath(): void
    {
        self::assertSame('/fr/about', RouteCatalog::get(RouteCatalog::ABOUT)->toPath(['locale' => 'fr']));
        self::assertSame('/en/about', RouteCatalog::get(RouteCatalog::ABOUT)->toPath(['locale' => 'en']));

        // The unprefixed entry route is the one that is genuinely static: it
        // has no language of its own because it exists to choose one.
        self::assertSame('/about', RouteCatalog::get(RouteCatalog::ABOUT_ENTRY)->toPath());
        self::assertFalse(RouteCatalog::get(RouteCatalog::ABOUT_ENTRY)->isDynamic());
    }
}
