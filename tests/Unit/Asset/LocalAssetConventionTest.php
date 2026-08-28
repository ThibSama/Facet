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

    public function testEveryFontBinaryHasRecordedProvenanceAndAnEffectiveFace(): void
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

        sort($woff2);
        self::assertSame([
            self::root() . '/resources/fonts/facet-lato-bold.woff2',
            self::root() . '/resources/fonts/facet-lato-regular.woff2',
        ], $woff2);

        $provenance = file_get_contents(self::root() . '/resources/fonts/README.md');
        $licence = file_get_contents(self::root() . '/resources/fonts/LICENSE-Lato.txt');
        self::assertIsString($provenance);
        self::assertIsString($licence);
        self::assertStringContainsString('SIL Open Font License 1.1', $provenance);
        self::assertStringContainsString('SIL OPEN FONT LICENSE Version 1.1', $licence);

        $checksums = [
            'facet-lato-regular.woff2' => '2e1eff147a26eaba324a5991dea698fc3cc935157bb097961550b4481dcf114a',
            'facet-lato-bold.woff2' => '3824666ebd10503bb52fa19a8fd7079d71c5c09d4acaaa1bcfa2fc57cbcf3f61',
        ];

        foreach ($checksums as $file => $expected) {
            self::assertSame($expected, hash_file('sha256', self::root() . '/resources/fonts/' . $file));
            self::assertStringContainsString($expected, $provenance);
        }

        $styles = file_get_contents(self::root() . '/resources/fonts/fonts.css');
        self::assertIsString($styles);
        self::assertSame(2, preg_match_all('/@font-face\s*\{/', $styles));
        self::assertSame(2, preg_match_all('/font-display:\s*swap/', $styles));
        self::assertStringContainsString("url('./facet-lato-regular.woff2') format('woff2')", $styles);
        self::assertStringContainsString("url('./facet-lato-bold.woff2') format('woff2')", $styles);
    }

    private function sharedStyles(): string
    {
        $styles = file_get_contents(self::root() . '/resources/css/app.css');
        self::assertIsString($styles);

        return $styles;
    }
}
