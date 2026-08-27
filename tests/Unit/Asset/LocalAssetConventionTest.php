<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Asset;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class LocalAssetConventionTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testTheFontPipelineHasNoRemoteRuntimeDependency(): void
    {
        $styles = file_get_contents(self::root() . '/resources/fonts/fonts.css');
        self::assertIsString($styles);

        self::assertStringNotContainsString('http://', $styles);
        self::assertStringNotContainsString('https://', $styles);
        self::assertStringContainsString("@import '../fonts/fonts.css';", $this->sharedStyles());
    }

    public function testNoUnprovenFontBinaryOrFaceIsCommitted(): void
    {
        $woff2 = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::root() . '/resources/fonts')
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'woff2') {
                $woff2[] = $file->getPathname();
            }
        }

        self::assertSame([], $woff2, 'A WOFF2 requires recorded project-local licence provenance.');

        $styles = file_get_contents(self::root() . '/resources/fonts/fonts.css');
        self::assertIsString($styles);
        self::assertDoesNotMatchRegularExpression('/@font-face\s*\{/', $styles);
    }

    private function sharedStyles(): string
    {
        $styles = file_get_contents(self::root() . '/resources/css/app.css');
        self::assertIsString($styles);

        return $styles;
    }
}
