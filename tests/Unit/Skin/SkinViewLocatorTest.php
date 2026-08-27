<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Skin;

use Facet\Skin\SkinRegistry;
use Facet\Tests\Support\ContentFactory;
use Facet\Skin\SkinRenderer;
use Facet\Skin\SkinViewLocator;
use Facet\Skin\UnknownViewException;
use PHPUnit\Framework\TestCase;

final class SkinViewLocatorTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testLocatesALogicalViewInsideTheSelectedSkin(): void
    {
        $locator = new SkinViewLocator(self::root());
        $skin = SkinRegistry::default()->defaultSkin();

        $path = $locator->locate($skin, 'page.home');

        self::assertFileExists($path);
        self::assertStringEndsWith('resources/skins/evolving-interface/views/page/home.php', $path);
    }

    public function testTrailingSlashInBasePathIsNormalised(): void
    {
        $skin = SkinRegistry::default()->defaultSkin();

        self::assertSame(
            (new SkinViewLocator(self::root()))->locate($skin, 'page.home'),
            (new SkinViewLocator(self::root() . '/'))->locate($skin, 'page.home')
        );
    }

    public function testAViewTheSkinDoesNotProvideFailsLoudly(): void
    {
        $locator = new SkinViewLocator(self::root());
        $skin = SkinRegistry::default()->defaultSkin();

        self::assertFalse($locator->has($skin, 'page.nonexistent'));

        $this->expectException(UnknownViewException::class);
        $this->expectExceptionMessage('page.nonexistent');

        $locator->locate($skin, 'page.nonexistent');
    }

    public function testMalformedViewIdentifiersCannotEscapeTheSkinDirectory(): void
    {
        $locator = new SkinViewLocator(self::root());
        $skin = SkinRegistry::default()->defaultSkin();

        self::assertFalse($locator->has($skin, '../../../etc/passwd'));

        $this->expectException(UnknownViewException::class);

        $locator->locate($skin, '../../../etc/passwd');
    }

    public function testRendererProducesMarkupForALogicalView(): void
    {
        $renderer = SkinRenderer::forBasePath(self::root());
        $skin = SkinRegistry::default()->defaultSkin();

        $html = $renderer->render($skin, 'page.home', [
            'assets' => \Facet\Asset\AssetBundle::empty(),
            'skin' => $skin,
            'appName' => 'Facet',
            'locale' => 'en',
            'environment' => 'testing',
            'path' => '/',
            'profile' => ContentFactory::profile(),
            'projects' => [ContentFactory::project()],
            'skills' => [ContentFactory::skill()],
            'experiences' => [ContentFactory::experience()],
        ]);

        self::assertStringContainsString('<!doctype html>', $html);
        self::assertStringContainsString('data-skin="evolving-interface"', $html);
        // The view renders the content it was handed, and nothing about the
        // runtime: the environment is shared data, not public copy.
        self::assertStringContainsString('Fixture Person', $html);
        self::assertStringNotContainsString('testing', $html);
    }

    public function testRendererEmitsNothingWhenTheViewIsUnknown(): void
    {
        $renderer = SkinRenderer::forBasePath(self::root());
        $skin = SkinRegistry::default()->defaultSkin();

        $this->expectException(UnknownViewException::class);

        $renderer->render($skin, 'page.nonexistent');
    }
}
