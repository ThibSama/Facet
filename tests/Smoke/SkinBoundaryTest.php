<?php

declare(strict_types=1);

namespace Facet\Tests\Smoke;

use Facet\Routing\RouteCatalog;
use Facet\Skin\SkinRegistry;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guards the skin boundary from the outside.
 *
 * The registry is allowed to name the one real skin — that is its job. Nothing
 * else under src/, and no shared runtime entrypoint, may name a skin or reach
 * into a skin directory: shared code addresses views logically and lets the
 * registry decide which files answer.
 */
final class SkinBoundaryTest extends TestCase
{
    /**
     * The only files permitted to name a concrete skin.
     *
     * @var list<string>
     */
    private const SKIN_AWARE_FILES = [
        'src/Skin/SkinRegistry.php',
    ];

    /**
     * @var list<string>
     */
    private const FORBIDDEN_SPELLINGS = [
        'evolving-interface',
        'evolving_interface',
        'evolvinginterface',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return list<string> project-relative paths
     */
    private static function phpFilesUnder(string $relative): array
    {
        $path = self::root() . '/' . $relative;
        self::assertDirectoryExists($path);

        $files = [];
        $prefix = strlen(self::root()) + 1;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), $prefix);
            }
        }

        sort($files);

        return $files;
    }

    public function testOnlyTheRegistryNamesAConcreteSkin(): void
    {
        $offenders = [];
        $checked = 0;

        foreach (self::phpFilesUnder('src') as $relative) {
            if (in_array($relative, self::SKIN_AWARE_FILES, true)) {
                continue;
            }

            $raw = file_get_contents(self::root() . '/' . $relative);
            self::assertIsString($raw);
            $checked++;

            foreach (self::FORBIDDEN_SPELLINGS as $spelling) {
                if (str_contains(mb_strtolower($raw), $spelling)) {
                    $offenders[] = $relative;
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'No source file was actually scanned');
        self::assertSame([], $offenders, 'Only the skin registry may name a concrete skin');
    }

    public function testTheRegistryReallyDoesNameTheSkin(): void
    {
        // A guard whose allowlist points at nothing proves nothing.
        foreach (self::SKIN_AWARE_FILES as $relative) {
            $raw = file_get_contents(self::root() . '/' . $relative);
            self::assertIsString($raw);
            self::assertStringContainsString(SkinRegistry::EVOLVING_INTERFACE, $raw);
        }
    }

    public function testSharedRuntimeNeverHardCodesSkinViewPaths(): void
    {
        $files = [...self::phpFilesUnder('src'), 'public/index.php', 'config/app.php'];

        foreach ($files as $relative) {
            // src/Skin is the subsystem that owns skin locations by definition;
            // the rule is about everything that merely consumes a skin.
            if (str_starts_with($relative, 'src/Skin/')) {
                continue;
            }

            $raw = file_get_contents(self::root() . '/' . $relative);
            self::assertIsString($raw);

            self::assertStringNotContainsString(
                'resources/skins/',
                $raw,
                $relative . ' must ask the registry for views instead of naming a skin directory'
            );
        }
    }

    public function testRoutesDeclareLogicalViewsRatherThanFiles(): void
    {
        foreach (RouteCatalog::all() as $name => $route) {
            $template = $route->template();

            self::assertMatchesRegularExpression(
                '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)*$/',
                $template,
                sprintf('Route "%s" must declare a logical view identifier', $name)
            );
            self::assertStringNotContainsString('/', $template);
            self::assertStringNotContainsString('.php', $template);
        }
    }

    public function testTheSelectedSkinAnswersTheHomeRouteLogicalView(): void
    {
        $skin = SkinRegistry::default()->defaultSkin();
        $template = RouteCatalog::get(RouteCatalog::HOME)->template();

        self::assertFileExists(self::root() . '/' . $skin->viewPath($template));
    }
}
