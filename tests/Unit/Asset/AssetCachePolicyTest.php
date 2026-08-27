<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Asset;

use Facet\Asset\AssetCachePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssetCachePolicyTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function fingerprintedAssets(): array
    {
        return [
            'JavaScript' => ['/build/assets/app-DqRW5FMF.js'],
            'CSS' => ['/build/assets/skin-evolving-interface-DksGBesD.css'],
            'WOFF2' => ['/build/assets/brand-Regular1.woff2'],
            'image with query' => ['/build/assets/project-A1b2C3d4.avif?v=1'],
        ];
    }

    #[DataProvider('fingerprintedAssets')]
    public function testOnlyFingerprintedBuildAssetsAreImmutable(string $path): void
    {
        self::assertTrue(AssetCachePolicy::isFingerprintedBuildAsset($path));
        self::assertSame(
            ['Cache-Control' => 'public, max-age=31536000, immutable'],
            AssetCachePolicy::headersForPublicPath($path)
        );
    }

    /** @return array<string, array{0: string}> */
    public static function revalidatedPaths(): array
    {
        return [
            'HTML' => ['/projects'],
            'manifest' => ['/build/manifest.json'],
            'config' => ['/config/app.php'],
            'unfingerprinted build file' => ['/build/assets/app.js'],
            'lookalike outside build' => ['/uploads/project-A1b2C3d4.avif'],
        ];
    }

    #[DataProvider('revalidatedPaths')]
    public function testNonFingerprintedPathsMustRevalidate(string $path): void
    {
        self::assertFalse(AssetCachePolicy::isFingerprintedBuildAsset($path));
        self::assertSame(['Cache-Control' => 'no-cache'], AssetCachePolicy::headersForPublicPath($path));
    }
}
