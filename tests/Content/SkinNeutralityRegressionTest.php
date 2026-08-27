<?php

declare(strict_types=1);

namespace Facet\Tests\Content;

use Facet\Content\CorpusLoader;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Canonical content must never name a skin.
 *
 * `evolving-interface` is the concrete case this regression exists for: the
 * content layer is built to be consumed by skins it has never heard of, so the
 * name of any particular one appearing in the corpus — or in the shared content
 * and routing code — means the boundary has been crossed.
 */
final class SkinNeutralityRegressionTest extends TestCase
{
    private const FORBIDDEN_SKIN_NAME = 'evolving-interface';

    /**
     * Spellings the same name could realistically arrive in.
     *
     * @var list<string>
     */
    private const FORBIDDEN_SPELLINGS = [
        'evolving-interface',
        'evolving_interface',
        'evolvinginterface',
        'evolving interface',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testLoadedCorpusNeverMentionsTheSkin(): void
    {
        $corpus = CorpusLoader::default()->load();

        foreach ($corpus->textFragments() as $fragment) {
            $normalised = mb_strtolower($fragment);

            foreach (self::FORBIDDEN_SPELLINGS as $spelling) {
                self::assertStringNotContainsString(
                    $spelling,
                    $normalised,
                    'Canonical content must not name a skin: ' . $fragment
                );
            }
        }

        foreach ($corpus->links() as $link) {
            self::assertStringNotContainsString(
                self::FORBIDDEN_SKIN_NAME,
                mb_strtolower($link->url())
            );
        }

        foreach ($corpus->projects() as $project) {
            self::assertStringNotContainsString(
                self::FORBIDDEN_SKIN_NAME,
                mb_strtolower($project->media()->reference())
            );
        }
    }

    public function testContentFilesNeverMentionTheSkin(): void
    {
        $directory = self::root() . '/content';

        self::assertDirectoryExists($directory);

        $checked = 0;

        foreach (glob($directory . '/*.json') ?: [] as $file) {
            $raw = file_get_contents($file);
            self::assertIsString($raw);

            $checked++;

            foreach (self::FORBIDDEN_SPELLINGS as $spelling) {
                self::assertStringNotContainsString(
                    $spelling,
                    mb_strtolower($raw),
                    basename($file) . ' must not name a skin'
                );
            }
        }

        self::assertGreaterThan(0, $checked, 'No content file was actually scanned');
    }

    public function testSharedContentAndRoutingCodeNeverMentionTheSkin(): void
    {
        $checked = 0;

        foreach (['src/Content', 'src/Routing'] as $relative) {
            $path = self::root() . '/' . $relative;

            self::assertDirectoryExists($path);

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $raw = file_get_contents($file->getPathname());
                self::assertIsString($raw);

                $checked++;

                foreach (self::FORBIDDEN_SPELLINGS as $spelling) {
                    self::assertStringNotContainsString(
                        $spelling,
                        mb_strtolower($raw),
                        $file->getFilename() . ' must not name a skin'
                    );
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'No source file was actually scanned');
    }

    public function testTheGuardActuallyDetectsTheName(): void
    {
        // A regression that cannot fail proves nothing.
        foreach (self::FORBIDDEN_SPELLINGS as $spelling) {
            self::assertStringContainsString(
                $spelling,
                'a corpus mentioning ' . $spelling . ' would be rejected'
            );
        }
    }
}
