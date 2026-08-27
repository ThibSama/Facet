<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Asset;

use Facet\Asset\ResponsiveImage;
use Facet\Support\ViteManifest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ResponsiveImageTest extends TestCase
{
    public function testItCarriesFingerprintedSourcesAndIntrinsicMetadata(): void
    {
        $manifest = $this->manifest([
            'resources/images/project.jpg' => ['file' => 'assets/project-A1b2C3d4.jpg'],
            'resources/images/project.webp' => ['file' => 'assets/project-E5f6G7h8.webp'],
            'resources/images/project.avif' => ['file' => 'assets/project-I9j0K1l2.avif'],
        ]);

        $image = ResponsiveImage::fromManifest(
            $manifest,
            'resources/images/project.jpg',
            1600,
            900,
            'Project interface overview',
            [
                'image/avif' => 'resources/images/project.avif',
                'image/webp' => 'resources/images/project.webp',
            ]
        );

        self::assertSame('/build/assets/project-A1b2C3d4.jpg', $image->source());
        self::assertSame(1600, $image->width());
        self::assertSame(900, $image->height());
        self::assertSame('Project interface overview', $image->description());
        self::assertSame([
            ['type' => 'image/avif', 'source' => '/build/assets/project-I9j0K1l2.avif'],
            ['type' => 'image/webp', 'source' => '/build/assets/project-E5f6G7h8.webp'],
        ], $image->modernSources());
    }

    public function testIntrinsicDimensionsAreRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ResponsiveImage::fromManifest($this->manifest([
            'resources/images/project.jpg' => ['file' => 'assets/project-A1b2C3d4.jpg'],
        ]), 'resources/images/project.jpg', 0, 900, 'Description');
    }

    /** @param array<string, array<string, mixed>> $entries */
    private function manifest(array $entries): ViteManifest
    {
        $path = tempnam(sys_get_temp_dir(), 'facet-image-manifest-');
        self::assertIsString($path);

        try {
            file_put_contents($path, json_encode($entries, JSON_THROW_ON_ERROR));

            return ViteManifest::fromFile($path);
        } finally {
            unlink($path);
        }
    }
}
