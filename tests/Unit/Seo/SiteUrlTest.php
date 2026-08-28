<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Seo;

use Facet\Config\Config;
use Facet\Seo\SiteUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SiteUrlTest extends TestCase
{
    public function testItNormalisesConfiguredBasePathsAndJoinsCanonicalPaths(): void
    {
        $url = SiteUrl::fromConfig(Config::fromArray([
            'APP_ENV' => 'production',
            'APP_URL' => 'https://PORTFOLIO.EXAMPLE/base/',
        ]));

        self::assertNotNull($url);
        self::assertSame('https://portfolio.example/base/', $url->absolute('/'));
        self::assertSame('https://portfolio.example/base/projects/kushim', $url->absolute('/projects/kushim'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidProductionUrls(): iterable
    {
        yield 'missing' => [''];
        yield 'relative' => ['/portfolio'];
        yield 'unsupported scheme' => ['ftp://portfolio.example'];
        yield 'query' => ['https://portfolio.example?tenant=facet'];
        yield 'fragment' => ['https://portfolio.example/#top'];
        yield 'credentials' => ['https://user:pass@portfolio.example'];
        yield 'localhost' => ['http://localhost:8000'];
        yield 'loopback' => ['http://127.0.0.1:8000'];
        yield 'private address' => ['http://192.168.1.10'];
    }

    #[DataProvider('invalidProductionUrls')]
    public function testItRefusesInvalidOrLocalProductionOrigins(string $candidate): void
    {
        self::assertNull(SiteUrl::fromConfig(Config::fromArray([
            'APP_ENV' => 'production',
            'APP_URL' => $candidate,
        ])));
    }

    public function testLocalDevelopmentMayUseLocalhost(): void
    {
        $url = SiteUrl::fromConfig(Config::fromArray([
            'APP_ENV' => 'local',
            'APP_URL' => 'http://localhost:8000',
        ]));

        self::assertNotNull($url);
        self::assertSame('http://localhost:8000/projects', $url->absolute('/projects'));
    }
}
