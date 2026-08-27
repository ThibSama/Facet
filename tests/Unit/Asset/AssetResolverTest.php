<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Asset;

use Facet\Asset\AssetResolver;
use Facet\Skin\SkinDefinition;
use Facet\Skin\SkinRegistry;
use Facet\Support\ViteManifest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AssetResolverTest extends TestCase
{
    public function testManifestResolutionIsStableDeduplicatedAndSkinIsolated(): void
    {
        $manifest = $this->manifest([
            'resources/js/app.ts' => [
                'file' => 'assets/app-A1b2C3d4.js',
                'css' => ['assets/app-E5f6G7h8.css'],
            ],
            'resources/skins/evolving-interface/skin.ts' => [
                'file' => 'assets/skin-selected-I9j0K1l2.js',
                'css' => ['assets/app-E5f6G7h8.css', 'assets/skin-selected-M3n4O5p6.css'],
            ],
            'resources/skins/fixture-unselected/skin.ts' => [
                'file' => 'assets/skin-unselected-Q7r8S9t0.js',
            ],
        ]);

        $bundle = AssetResolver::usingManifest($manifest)->resolve(SkinRegistry::default()->defaultSkin());

        self::assertSame(['/build/assets/app-A1b2C3d4.js', '/build/assets/skin-selected-I9j0K1l2.js'], $bundle->scripts());
        self::assertSame(['/build/assets/app-E5f6G7h8.css', '/build/assets/skin-selected-M3n4O5p6.css'], $bundle->styles());
        self::assertFalse($bundle->references('/build/assets/skin-unselected-Q7r8S9t0.js'));
    }

    public function testARequiredSelectedEntrypointFailsExplicitly(): void
    {
        $resolver = AssetResolver::usingManifest($this->manifest([
            'resources/js/app.ts' => ['file' => 'assets/app-A1b2C3d4.js'],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('resources/skins/evolving-interface/skin.ts');

        $resolver->resolve(SkinRegistry::default()->defaultSkin());
    }

    public function testProductionManifestAssetsMustBeFingerprinted(): void
    {
        $resolver = AssetResolver::usingManifest($this->manifest([
            'resources/js/app.ts' => ['file' => 'assets/app.js'],
        ]), ['resources/js/app.ts']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-fingerprinted asset');

        $resolver->resolve(SkinRegistry::default()->defaultSkin());
    }

    public function testDevelopmentEntrypointsAreStableAndDeduplicated(): void
    {
        $skin = SkinDefinition::define(
            'test-skin',
            'test-skin',
            'resources/skins/test-skin/views',
            ['resources/js/app.ts', 'resources/skins/test-skin/skin.ts']
        );

        $bundle = AssetResolver::usingDevServer('https://vite.example.test:5173/')->resolve($skin);

        self::assertSame([
            'https://vite.example.test:5173/@vite/client',
            'https://vite.example.test:5173/resources/js/app.ts',
            'https://vite.example.test:5173/resources/skins/test-skin/skin.ts',
        ], $bundle->scripts());
        self::assertSame([
            'resources/js/app.ts',
            'resources/skins/test-skin/skin.ts',
        ], $bundle->entrypoints());
    }

    public function testDevelopmentOriginMustBeAnOriginRatherThanAnArbitraryUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AssetResolver::usingDevServer('https://vite.example.test/path?query=yes');
    }

    /** @param array<string, array<string, mixed>> $entries */
    private function manifest(array $entries): ViteManifest
    {
        $path = tempnam(sys_get_temp_dir(), 'facet-manifest-');
        self::assertIsString($path);

        try {
            file_put_contents($path, json_encode($entries, JSON_THROW_ON_ERROR));

            return ViteManifest::fromFile($path);
        } finally {
            unlink($path);
        }
    }
}
