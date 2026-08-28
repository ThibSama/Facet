<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Seo;

use Facet\Config\Config;
use Facet\Http\Application;
use Facet\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SeoFallbackTest extends TestCase
{
    /** @return iterable<string, array{array<string, string>}> */
    public static function unsafeProductionOrigins(): iterable
    {
        yield 'missing APP_URL' => [[]];
        yield 'invalid APP_URL' => [['APP_URL' => 'not-a-url']];
        yield 'localhost APP_URL' => [['APP_URL' => 'http://localhost:8000']];
    }

    /** @param array<string, string> $origin */
    #[DataProvider('unsafeProductionOrigins')]
    public function testPublicHtmlStillRendersButOmitsUrlBearingSeoWhenOriginIsUnsafe(array $origin): void
    {
        $app = Application::boot(dirname(__DIR__, 3), Config::fromArray($origin + [
            'APP_NAME' => 'Facet',
            'APP_ENV' => 'production',
            'APP_KEY' => 'test-key',
            'APP_LOCALE' => 'fr',
        ]));

        $response = $app->handle(Request::create('GET', '/'));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="description"', $response->body());
        self::assertStringNotContainsString('rel="canonical"', $response->body());
        self::assertStringNotContainsString('property="og:url"', $response->body());
        self::assertStringNotContainsString('localhost', $response->body());
    }

    public function testUrlDependentTechnicalEndpointsFailClosedAndNoindex(): void
    {
        $app = Application::boot(dirname(__DIR__, 3), Config::fromArray([
            'APP_NAME' => 'Facet',
            'APP_ENV' => 'production',
            'APP_KEY' => 'test-key',
        ]));

        foreach (['/sitemap.xml', '/robots.txt'] as $path) {
            $response = $app->handle(Request::create('GET', $path));
            self::assertSame(500, $response->status(), $path);
            self::assertSame('noindex, nofollow', $response->header('X-Robots-Tag'), $path);
            self::assertStringNotContainsString('localhost', $response->body(), $path);
        }
    }
}
