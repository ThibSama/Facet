<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Skin;

use Facet\Skin\SkinCapability;
use Facet\Skin\SkinDefinition;
use Facet\Skin\UnknownViewException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SkinDefinitionTest extends TestCase
{
    private static function definition(): SkinDefinition
    {
        return SkinDefinition::define(
            'sample-skin',
            'sample-skin',
            'resources/skins/sample-skin/views',
            ['resources/skins/sample-skin/skin.ts'],
            [SkinCapability::ServerRenderedViews, SkinCapability::ProgressiveEnhancement]
        );
    }

    public function testDeclaresIdNamespaceEntrypointsAndCapabilities(): void
    {
        $skin = self::definition();

        self::assertSame('sample-skin', $skin->id());
        self::assertTrue($skin->is('sample-skin'));
        self::assertSame('sample-skin', $skin->viewNamespace());
        self::assertSame('resources/skins/sample-skin/views', $skin->viewDirectory());
        self::assertSame(['resources/skins/sample-skin/skin.ts'], $skin->assetEntrypoints());
        self::assertTrue($skin->supports(SkinCapability::ServerRenderedViews));
        self::assertTrue($skin->supports(SkinCapability::ProgressiveEnhancement));
        self::assertFalse($skin->supports(SkinCapability::IsolatedStylesheet));
    }

    public function testCapabilitiesAreDeduplicated(): void
    {
        $skin = SkinDefinition::define(
            'sample-skin',
            'sample-skin',
            'resources/skins/sample-skin/views',
            ['a.ts'],
            [SkinCapability::ServerRenderedViews, SkinCapability::ServerRenderedViews]
        );

        self::assertSame([SkinCapability::ServerRenderedViews], $skin->capabilities());
    }

    public function testTrailingSlashInViewDirectoryIsNormalised(): void
    {
        $skin = SkinDefinition::define('a-skin', 'a-skin', 'resources/skins/a-skin/views/', ['a.ts']);

        self::assertSame('resources/skins/a-skin/views', $skin->viewDirectory());
    }

    public function testLogicalViewBecomesASkinOwnedPath(): void
    {
        $skin = self::definition();

        self::assertSame('resources/skins/sample-skin/views/page/home.php', $skin->viewPath('page.home'));
        self::assertSame(
            'resources/skins/sample-skin/views/page/projects/show.php',
            $skin->viewPath('page.projects.show')
        );
    }

    public function testMalformedLogicalViewsAreRejected(): void
    {
        $skin = self::definition();

        foreach (['', '../secret', 'page..home', 'page.Home', 'page.home/', '/etc/passwd', '.home'] as $malformed) {
            try {
                $skin->viewPath($malformed);
                self::fail(sprintf('Expected "%s" to be rejected as a logical view.', $malformed));
            } catch (UnknownViewException $exception) {
                self::assertStringContainsString('malformed', $exception->getMessage());
            }
        }
    }

    /**
     * @return array<string, array{string, string, string, list<string>}>
     */
    public static function invalidDeclarationProvider(): array
    {
        return [
            'empty id' => ['', 'ns', 'resources/skins/x/views', ['a.ts']],
            'uppercase id' => ['Skin', 'ns', 'resources/skins/x/views', ['a.ts']],
            'underscored id' => ['a_skin', 'ns', 'resources/skins/x/views', ['a.ts']],
            'invalid namespace' => ['a-skin', 'A Namespace', 'resources/skins/x/views', ['a.ts']],
            'absolute view dir' => ['a-skin', 'a-skin', '/etc', ['a.ts']],
            'traversing view dir' => ['a-skin', 'a-skin', 'resources/../../etc', ['a.ts']],
            'empty view dir' => ['a-skin', 'a-skin', '', ['a.ts']],
            'no entrypoints' => ['a-skin', 'a-skin', 'resources/skins/x/views', []],
            'traversing entrypoint' => ['a-skin', 'a-skin', 'resources/skins/x/views', ['../a.ts']],
            'empty entrypoint' => ['a-skin', 'a-skin', 'resources/skins/x/views', ['']],
        ];
    }

    /**
     * @param list<string> $entrypoints
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDeclarationProvider')]
    public function testInvalidDeclarationsAreRejected(
        string $id,
        string $namespace,
        string $directory,
        array $entrypoints
    ): void {
        $this->expectException(InvalidArgumentException::class);

        SkinDefinition::define($id, $namespace, $directory, $entrypoints);
    }
}
