<?php

declare(strict_types=1);

namespace Facet\Tests\Unit;

use Facet\Config\Config;
use Facet\Config\MissingConfigurationException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testReturnsDefaultForOptionalKey(): void
    {
        $config = Config::fromArray([]);

        self::assertSame('Facet', $config->get('APP_NAME', 'Facet'));
    }

    public function testReadsPresentValue(): void
    {
        $config = Config::fromArray(['APP_NAME' => 'Portfolio']);

        self::assertSame('Portfolio', $config->get('APP_NAME', 'Facet'));
    }

    public function testEmptyStringCountsAsAbsent(): void
    {
        $config = Config::fromArray(['APP_NAME' => '']);

        self::assertFalse($config->has('APP_NAME'));
        self::assertSame('Facet', $config->get('APP_NAME', 'Facet'));
    }

    public function testSensitiveValueHasNoFallback(): void
    {
        $config = Config::fromArray([]);

        $this->expectException(MissingConfigurationException::class);
        $config->get('APP_KEY', 'insecure-default');
    }

    public function testRequireThrowsWhenMissing(): void
    {
        $this->expectException(MissingConfigurationException::class);
        Config::fromArray([])->require('APP_KEY');
    }

    public function testRequireReturnsValueWhenPresent(): void
    {
        $config = Config::fromArray(['APP_KEY' => 'abc123']);

        self::assertSame('abc123', $config->require('APP_KEY'));
    }

    public function testEnvironmentDefaultsToProduction(): void
    {
        self::assertSame('production', Config::fromArray([])->environment());
        self::assertTrue(Config::fromArray([])->isProduction());
    }

    public function testDebugIsNeverEnabledInProduction(): void
    {
        $config = Config::fromArray(['APP_ENV' => 'production', 'APP_DEBUG' => 'true']);

        self::assertFalse($config->isDebug());
    }

    public function testDebugMayBeEnabledOutsideProduction(): void
    {
        $config = Config::fromArray(['APP_ENV' => 'local', 'APP_DEBUG' => 'true']);

        self::assertTrue($config->isDebug());
    }

    public function testDebugDefaultsOffOutsideProduction(): void
    {
        self::assertFalse(Config::fromArray(['APP_ENV' => 'local'])->isDebug());
    }
}
