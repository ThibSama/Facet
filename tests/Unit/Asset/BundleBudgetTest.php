<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Asset;

use Facet\Asset\AssetCachePolicy;
use PHPUnit\Framework\TestCase;

/**
 * The publication performance budgets, asserted against the real build.
 *
 * The budgets themselves are recorded in
 * docs/decisions/PORT-104-phase-4-gate.md; until this file existed they were
 * only ever *narrated* there. A budget nothing executes is a budget that
 * regresses the first time somebody adds a library, so the numbers live here
 * as well, where the build has to keep agreeing with them.
 *
 * Everything is read from the emitted artefacts rather than from source: what
 * a visitor pays for is the built chunk, and only the manifest knows which
 * chunk that is. `gzencode` at level 9 is the measure — it is the same deflate
 * the CLI `gzip -9` runs, minus the ~16 bytes of filename and mtime the CLI
 * writes into its header, so a figure here reads a shade under an equivalent
 * `gzip -9 -c | wc -c`.
 *
 * The one thing this file deliberately does *not* assert is a transport
 * header. `AssetCachePolicy` classifies; the production web server applies —
 * PHP never proxies a Vite artefact. Proving the classifier answers correctly
 * for the filenames this build actually emits is the half that belongs in the
 * repository; the wire is a deployment concern.
 */
final class BundleBudgetTest extends TestCase
{
    /** Critical JS is everything a document loads before it is interactive. */
    private const CRITICAL_JS_GZIP_BUDGET = 51_200;

    /** A deferred chunk: reached only through a dynamic import, if at all. */
    private const VISUAL_CHUNK_GZIP_BUDGET = 256_000;

    /** Shared with tools/check-font-subset.py, which gates the sources. */
    private const WOFF2_TOTAL_BUDGET = 122_880;

    /**
     * The entrypoints a rendered document actually references: the shared
     * layer plus the one real skin. `fixture-unselected` is built on purpose
     * and never rendered, so charging it to the budget would measure a file no
     * visitor can receive.
     */
    private const CRITICAL_ENTRIES = [
        'resources/js/app.ts',
        'resources/skins/evolving-interface/skin.ts',
    ];

    private const VISUAL_ENTRY = 'resources/skins/evolving-interface/hero.ts';

    /**
     * PORT-134's game. It is reached only when a visitor presses Play, so it
     * is charged to the deferred budget rather than the critical one — and it
     * is listed here so that "deferred" stays a property the build proves
     * rather than a claim the module makes about itself.
     */
    private const RUN_ENTRY = 'resources/skins/evolving-interface/satoshi-run/run.ts';

    /**
     * Every chunk the skin entry is allowed to reach dynamically, in the order
     * Rollup records them. The list is exact on purpose: a new deferred chunk
     * is a new thing a visitor can be made to download, and it should not be
     * possible to add one without this file being edited.
     */
    private const DEFERRED_ENTRIES = [self::VISUAL_ENTRY, self::RUN_ENTRY];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, array<string, mixed>> */
    private static function manifest(): array
    {
        $path = self::root() . '/public/build/manifest.json';

        if (!is_readable($path)) {
            self::markTestSkipped('No build present. Run `npm run build` (composer quality does).');
        }

        $raw = file_get_contents($path);
        self::assertIsString($raw);

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);

        /** @var array<string, array<string, mixed>> $decoded */
        return $decoded;
    }

    private static function bytes(string $file): string
    {
        $contents = file_get_contents(self::root() . '/public/build/' . $file);
        self::assertIsString($contents, $file . ' must exist in the build');

        return $contents;
    }

    private static function gzipped(string $file): int
    {
        $compressed = gzencode(self::bytes($file), 9);
        self::assertIsString($compressed, $file . ' must be compressible');

        return strlen($compressed);
    }

    private static function fileFor(string $source): string
    {
        $entry = self::manifest()[$source] ?? null;
        self::assertIsArray($entry, $source . ' must be a manifest entry');
        self::assertIsString($entry['file'] ?? null);

        return $entry['file'];
    }

    public function testCriticalJavaScriptStaysUnderBudgetExcludingTheDeferredChunk(): void
    {
        $total = 0;

        foreach (self::CRITICAL_ENTRIES as $source) {
            $entry = self::manifest()[$source] ?? null;
            self::assertIsArray($entry);

            // A static import would be paid for on first load and so belongs
            // in this sum; the hero is reached dynamically and must not.
            self::assertSame(
                [],
                $entry['imports'] ?? [],
                $source . ' must not statically import a further chunk'
            );

            $total += self::gzipped(self::fileFor($source));
        }

        self::assertLessThanOrEqual(
            self::CRITICAL_JS_GZIP_BUDGET,
            $total,
            sprintf('critical JS is %d B gzip, over the %d B budget', $total, self::CRITICAL_JS_GZIP_BUDGET)
        );
    }

    public function testEveryDeferredChunkStaysDeferredAndUnderTheVisualBudget(): void
    {
        $skin = self::manifest()['resources/skins/evolving-interface/skin.ts'] ?? null;
        self::assertIsArray($skin);

        // The budget is only meaningful while these chunks stay deferred: one
        // promoted to a static import would be critical JS wearing this name.
        self::assertSame(self::DEFERRED_ENTRIES, $skin['dynamicImports'] ?? null);
        self::assertSame([], $skin['imports'] ?? []);

        foreach (self::DEFERRED_ENTRIES as $source) {
            $entry = self::manifest()[$source] ?? null;
            self::assertIsArray($entry, $source . ' must be a manifest entry');
            self::assertTrue($entry['isDynamicEntry'] ?? false, $source . ' must be a dynamic entry');
            self::assertArrayNotHasKey('isEntry', $entry, $source . ' is not an entrypoint PHP can emit');

            // A deferred chunk's stylesheet is deferred with it, so it is part
            // of what pressing the button actually costs and is measured here.
            $size = self::gzipped(self::fileFor($source));

            foreach ($entry['css'] ?? [] as $stylesheet) {
                self::assertIsString($stylesheet);
                $size += self::gzipped($stylesheet);
            }

            self::assertLessThanOrEqual(
                self::VISUAL_CHUNK_GZIP_BUDGET,
                $size,
                sprintf('%s is %d B gzip, over the %d B budget', $source, $size, self::VISUAL_CHUNK_GZIP_BUDGET)
            );
        }
    }

    /**
     * The shipped font weight, not the source weight.
     *
     * `tools/check-font-subset.py` gates resources/fonts against the same
     * number. This asserts the copies the build emitted are byte-identical to
     * the gated sources, so the two figures can never quietly diverge.
     */
    public function testEmittedFontsMatchTheirGatedSourcesAndTotalBudget(): void
    {
        $fonts = [
            'resources/fonts/facet-lato-regular.woff2',
            'resources/fonts/facet-lato-bold.woff2',
        ];

        $total = 0;

        foreach ($fonts as $source) {
            $emitted = self::bytes(self::fileFor($source));
            $original = file_get_contents(self::root() . '/' . $source);
            self::assertIsString($original);

            self::assertSame(
                hash('sha256', $original),
                hash('sha256', $emitted),
                $source . ' must reach the build unaltered'
            );

            $total += strlen($emitted);
        }

        self::assertLessThanOrEqual(
            self::WOFF2_TOTAL_BUDGET,
            $total,
            sprintf('emitted WOFF2 totals %d B, over the %d B budget', $total, self::WOFF2_TOTAL_BUDGET)
        );
    }

    /**
     * Every file this build emits is fingerprinted, and so is classified
     * one-year immutable; the manifest that names them is not, and must be
     * revalidated or a deploy would be invisible behind a year-old cache.
     */
    public function testEveryEmittedAssetClassifiesAsImmutableAndTheManifestDoesNot(): void
    {
        $paths = [];

        foreach (self::manifest() as $entry) {
            foreach ([[$entry['file'] ?? null], $entry['css'] ?? [], $entry['assets'] ?? []] as $group) {
                foreach ($group as $file) {
                    if (is_string($file)) {
                        $paths[] = '/build/' . $file;
                    }
                }
            }
        }

        $paths = array_values(array_unique($paths));
        self::assertNotEmpty($paths);

        foreach ($paths as $path) {
            self::assertTrue(
                AssetCachePolicy::isFingerprintedBuildAsset($path),
                $path . ' must be fingerprinted so it can be cached immutably'
            );
            self::assertSame(
                ['Cache-Control' => AssetCachePolicy::IMMUTABLE],
                AssetCachePolicy::headersForPublicPath($path)
            );
        }

        self::assertSame(
            ['Cache-Control' => AssetCachePolicy::REVALIDATE],
            AssetCachePolicy::headersForPublicPath('/build/manifest.json')
        );
    }
}
