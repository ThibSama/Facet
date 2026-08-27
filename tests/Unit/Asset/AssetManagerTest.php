<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Asset;

use Facet\Asset\AssetManager;
use Facet\Config\Config;
use Facet\Skin\SkinRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AssetManagerTest extends TestCase
{
    public function testProductionRequiresAReadableManifestAtConstruction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Vite manifest not found');

        AssetManager::fromConfig(
            Config::fromArray(['APP_ENV' => 'production']),
            __DIR__ . '/missing-manifest.json'
        );
    }

    public function testDevelopmentUsesTheConfiguredOriginWithoutAConnection(): void
    {
        $manager = AssetManager::fromConfig(
            Config::fromArray([
                'APP_ENV' => 'local',
                'VITE_DEV_SERVER_ORIGIN' => 'http://127.0.0.1:65534',
            ]),
            __DIR__ . '/missing-manifest.json'
        );

        $bundle = $manager->resolve(SkinRegistry::default()->defaultSkin());

        self::assertTrue($manager->isDevelopmentServer());
        self::assertSame([
            'http://127.0.0.1:65534/@vite/client',
            'http://127.0.0.1:65534/resources/js/app.ts',
            'http://127.0.0.1:65534/resources/skins/evolving-interface/skin.ts',
        ], $bundle->scripts());
        self::assertSame([], $bundle->styles());
    }

    public function testDevelopmentWithoutAnOriginMayRenderWithoutALocalBuild(): void
    {
        $manager = AssetManager::fromConfig(
            Config::fromArray(['APP_ENV' => 'local']),
            __DIR__ . '/missing-manifest.json'
        );

        $bundle = $manager->resolve(SkinRegistry::default()->defaultSkin());

        self::assertFalse($manager->isDevelopmentServer());
        self::assertTrue($bundle->isEmpty());
        self::assertSame([
            'resources/js/app.ts',
            'resources/skins/evolving-interface/skin.ts',
        ], $bundle->missing());
    }
}
