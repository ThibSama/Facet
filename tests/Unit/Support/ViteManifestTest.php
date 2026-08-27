<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Support;

use Facet\Support\ViteManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ViteManifestTest extends TestCase
{
    public function testItResolvesBuiltFilesAndStylesUnderThePublicBuildPath(): void
    {
        $manifest = $this->manifest([
            'resources/js/app.ts' => [
                'file' => 'assets/app-AbCd1234.js',
                'css' => ['assets/app-EfGh5678.css'],
            ],
        ]);

        self::assertSame('/build/assets/app-AbCd1234.js', $manifest->script('resources/js/app.ts'));
        self::assertSame(['/build/assets/app-EfGh5678.css'], $manifest->styles('resources/js/app.ts'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidManifests(): array
    {
        return [
            'invalid JSON' => ['not-json'],
            'non-object entry' => ['{"resources/js/app.ts":"asset.js"}'],
            'missing built file' => ['{"resources/js/app.ts":{"css":[]}}'],
            'invalid CSS list' => ['{"resources/js/app.ts":{"file":"asset.js","css":"asset.css"}}'],
        ];
    }

    #[DataProvider('invalidManifests')]
    public function testInvalidManifestDataFailsExplicitly(string $contents): void
    {
        $path = $this->file($contents);

        try {
            $this->expectException(RuntimeException::class);
            ViteManifest::fromFile($path);
        } finally {
            unlink($path);
        }
    }

    /** @param array<string, array<string, mixed>> $entries */
    private function manifest(array $entries): ViteManifest
    {
        $path = $this->file(json_encode($entries, JSON_THROW_ON_ERROR));

        try {
            return ViteManifest::fromFile($path);
        } finally {
            unlink($path);
        }
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'facet-manifest-');
        self::assertIsString($path);
        file_put_contents($path, $contents);

        return $path;
    }
}
