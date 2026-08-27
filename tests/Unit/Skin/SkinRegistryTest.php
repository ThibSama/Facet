<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Skin;

use Facet\Skin\SkinCapability;
use Facet\Skin\SkinDefinition;
use Facet\Skin\SkinRegistry;
use Facet\Skin\SkinViewLocator;
use Facet\Skin\UnknownSkinException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SkinRegistryTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testRegistryContainsExactlyTheOneRealMvpSkin(): void
    {
        $registry = SkinRegistry::default();

        self::assertSame([SkinRegistry::EVOLVING_INTERFACE], $registry->ids());
        self::assertSame(1, $registry->count());
    }

    public function testTheRealSkinIsTheDefault(): void
    {
        $registry = SkinRegistry::default();

        self::assertSame(SkinRegistry::EVOLVING_INTERFACE, $registry->defaultId());
        self::assertTrue($registry->defaultSkin()->is(SkinRegistry::EVOLVING_INTERFACE));
    }

    public function testTheRealSkinDeclaresItsLocationEntrypointsAndCapabilities(): void
    {
        $skin = SkinRegistry::default()->defaultSkin();

        self::assertSame(SkinRegistry::EVOLVING_INTERFACE, $skin->viewNamespace());
        self::assertDirectoryExists(self::root() . '/' . $skin->viewDirectory());
        self::assertSame(
            ['resources/skins/evolving-interface/skin.ts'],
            $skin->assetEntrypoints()
        );

        foreach ($skin->assetEntrypoints() as $entrypoint) {
            self::assertFileExists(self::root() . '/' . $entrypoint, 'A declared entrypoint must exist on disk');
        }

        foreach (
            [
                SkinCapability::ServerRenderedViews,
                SkinCapability::ProgressiveEnhancement,
                SkinCapability::IsolatedStylesheet,
            ] as $capability
        ) {
            self::assertTrue($skin->supports($capability), $capability->value . ' must be declared');
        }
    }

    public function testDeclaredCapabilitiesAreBackedByRealArtefacts(): void
    {
        $skin = SkinRegistry::default()->defaultSkin();
        $locator = new SkinViewLocator(self::root());

        self::assertTrue(
            $locator->has($skin, 'page.home'),
            'ServerRenderedViews requires at least one resolvable logical view'
        );
        self::assertFileExists(
            self::root() . '/resources/skins/evolving-interface/skin.ts',
            'ProgressiveEnhancement requires a module entrypoint'
        );
        self::assertFileExists(
            self::root() . '/resources/skins/evolving-interface/skin.css',
            'IsolatedStylesheet requires a skin-owned stylesheet'
        );
    }

    public function testUnknownIdBehaviourIsDeterministic(): void
    {
        $registry = SkinRegistry::default();

        self::assertFalse($registry->has('no-such-skin'));
        self::assertNull($registry->find('no-such-skin'));
        self::assertNull($registry->find(null));
        self::assertNull($registry->find(''));

        // The one sanctioned degradation, and it always lands on the default.
        foreach ([null, '', 'no-such-skin', 'EVOLVING-INTERFACE', 'evolving_interface'] as $unknown) {
            self::assertTrue(
                $registry->findOrDefault($unknown)->is(SkinRegistry::EVOLVING_INTERFACE),
                'Unknown ids must resolve to the default skin'
            );
        }
    }

    public function testStrictLookupThrowsAndNamesTheRegisteredSkins(): void
    {
        $this->expectException(UnknownSkinException::class);
        $this->expectExceptionMessage('Unknown skin "no-such-skin"');

        SkinRegistry::default()->get('no-such-skin');
    }

    public function testStrictLookupReturnsTheDeclaredSkin(): void
    {
        $skin = SkinRegistry::default()->get(SkinRegistry::EVOLVING_INTERFACE);

        self::assertSame(SkinRegistry::EVOLVING_INTERFACE, $skin->id());
    }

    public function testDefaultRegistryIsStable(): void
    {
        self::assertSame(SkinRegistry::default(), SkinRegistry::default());
    }

    public function testDuplicateIdsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate skin id');

        SkinRegistry::create([self::fixture('a-skin'), self::fixture('a-skin')], 'a-skin');
    }

    public function testEmptyRegistryIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SkinRegistry::create([], 'a-skin');
    }

    public function testUnregisteredDefaultIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not registered');

        SkinRegistry::create([self::fixture('a-skin')], 'b-skin');
    }

    private static function fixture(string $id): SkinDefinition
    {
        return SkinDefinition::define($id, $id, 'resources/skins/' . $id . '/views', ['resources/skins/' . $id . '/skin.ts']);
    }
}
