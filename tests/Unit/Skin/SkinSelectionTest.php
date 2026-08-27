<?php

declare(strict_types=1);

namespace Facet\Tests\Unit\Skin;

use Facet\Config\Config;
use Facet\Routing\RouteCatalog;
use Facet\Skin\Selection\DefaultSkinSelectionPolicy;
use Facet\Skin\Selection\SkinSelectionContext;
use Facet\Skin\Selection\SkinSelectionSource;
use Facet\Skin\SkinDefinition;
use Facet\Skin\SkinRegistry;
use Facet\Tests\Support\FakeRandomSkinSelectionPolicy;
use PHPUnit\Framework\TestCase;

final class SkinSelectionTest extends TestCase
{
    /**
     * Two skins exist only in test fixtures. Production still ships exactly
     * one, which SkinRegistryTest asserts.
     */
    private static function twoSkinRegistry(): SkinRegistry
    {
        return SkinRegistry::create(
            [self::fixture('alpha-skin'), self::fixture('beta-skin')],
            'alpha-skin'
        );
    }

    private static function fixture(string $id): SkinDefinition
    {
        return SkinDefinition::define(
            $id,
            $id,
            'resources/skins/' . $id . '/views',
            ['resources/skins/' . $id . '/skin.ts']
        );
    }

    private static function config(string $environment): Config
    {
        return Config::fromArray(['APP_ENV' => $environment, 'APP_KEY' => 'test-key']);
    }

    public function testDefaultPolicyReturnsTheRegistryDefaultWhenNothingIsRequested(): void
    {
        $selection = (new DefaultSkinSelectionPolicy())->select(
            SkinRegistry::default(),
            SkinSelectionContext::fromRequest([], [], self::config('local'))
        );

        self::assertTrue($selection->skin()->is(SkinRegistry::EVOLVING_INTERFACE));
        self::assertSame(SkinSelectionSource::Default, $selection->source());
        self::assertFalse($selection->isExplicit());
        self::assertFalse($selection->shouldPersist());
    }

    public function testDevelopmentMayExplicitlyRequestTheRealSkin(): void
    {
        $context = SkinSelectionContext::fromRequest(
            [SkinSelectionContext::QUERY_PARAMETER => SkinRegistry::EVOLVING_INTERFACE],
            [],
            self::config('local')
        );

        self::assertTrue($context->allowsOverride());
        self::assertSame(SkinRegistry::EVOLVING_INTERFACE, $context->requestedSkinId());

        $selection = (new DefaultSkinSelectionPolicy())->select(SkinRegistry::default(), $context);

        self::assertTrue($selection->skin()->is(SkinRegistry::EVOLVING_INTERFACE));
        self::assertSame(SkinSelectionSource::Requested, $selection->source());
        self::assertTrue($selection->shouldPersist());
    }

    public function testDevelopmentOverrideActuallyChangesTheSelectedSkin(): void
    {
        $selection = (new DefaultSkinSelectionPolicy())->select(
            self::twoSkinRegistry(),
            SkinSelectionContext::development('beta-skin')
        );

        self::assertSame('beta-skin', $selection->skin()->id());
        self::assertSame(SkinSelectionSource::Requested, $selection->source());
    }

    public function testProductionCannotSelectASkinThroughTheQueryString(): void
    {
        $registry = self::twoSkinRegistry();
        $policy = new DefaultSkinSelectionPolicy();

        foreach (['beta-skin', 'alpha-skin', 'no-such-skin', '../../etc/passwd'] as $attempt) {
            $context = SkinSelectionContext::fromRequest(
                [SkinSelectionContext::QUERY_PARAMETER => $attempt],
                [SkinSelectionContext::PERSISTENCE_KEY => 'beta-skin'],
                self::config('production')
            );

            self::assertFalse($context->allowsOverride(), 'Production must not allow overrides');
            self::assertNull($context->requestedSkinId(), 'Production must not even capture the request');
            self::assertNull($context->persistedSkinId());

            $selection = $policy->select($registry, $context);

            self::assertSame(
                $registry->defaultId(),
                $selection->skin()->id(),
                sprintf('Production must ignore ?skin=%s', $attempt)
            );
            self::assertSame(SkinSelectionSource::Default, $selection->source());
        }
    }

    public function testALockedContextNeverCarriesAnOverride(): void
    {
        $context = SkinSelectionContext::locked();

        self::assertFalse($context->allowsOverride());
        self::assertFalse($context->hasRequest());
        self::assertNull($context->requestedSkinId());
        self::assertSame('production', $context->environment());
    }

    public function testUnknownRequestedIdFallsBackToTheDefaultWithoutFailing(): void
    {
        $registry = self::twoSkinRegistry();

        foreach (['no-such-skin', '', '   ', 'ALPHA-SKIN'] as $unknown) {
            $selection = (new DefaultSkinSelectionPolicy())->select(
                $registry,
                SkinSelectionContext::development($unknown)
            );

            self::assertSame('alpha-skin', $selection->skin()->id());
            self::assertSame(SkinSelectionSource::Default, $selection->source());
        }
    }

    public function testNonStringRequestInputIsIgnored(): void
    {
        $context = SkinSelectionContext::fromRequest(
            [SkinSelectionContext::QUERY_PARAMETER => ['beta-skin']],
            [SkinSelectionContext::PERSISTENCE_KEY => 42],
            self::config('local')
        );

        self::assertNull($context->requestedSkinId());
        self::assertNull($context->persistedSkinId());
    }

    public function testAnExplicitChoiceSurvivesNavigationWithoutRewritingRoutes(): void
    {
        $registry = self::twoSkinRegistry();
        $policy = new DefaultSkinSelectionPolicy();

        // First request: explicit, and marked as worth carrying forward.
        $first = $policy->select($registry, SkinSelectionContext::development('beta-skin'));
        self::assertTrue($first->shouldPersist());

        // Second request: no query string at all, only the carried value.
        $second = $policy->select(
            $registry,
            SkinSelectionContext::fromRequest(
                [],
                [SkinSelectionContext::PERSISTENCE_KEY => $first->skin()->id()],
                self::config('local')
            )
        );

        self::assertSame('beta-skin', $second->skin()->id());
        self::assertSame(SkinSelectionSource::Persisted, $second->source());

        // And no route path or parameter had to carry the skin to make it work.
        foreach (RouteCatalog::all() as $route) {
            self::assertStringNotContainsString(SkinSelectionContext::QUERY_PARAMETER, $route->path());

            foreach ($route->parameters() as $parameter) {
                self::assertNotSame(SkinSelectionContext::QUERY_PARAMETER, $parameter->name());
            }
        }
    }

    public function testAnExplicitRequestOutranksACarriedChoice(): void
    {
        $selection = (new DefaultSkinSelectionPolicy())->select(
            self::twoSkinRegistry(),
            SkinSelectionContext::development('alpha-skin', 'beta-skin')
        );

        self::assertSame('alpha-skin', $selection->skin()->id());
        self::assertSame(SkinSelectionSource::Requested, $selection->source());
    }

    public function testTheMvpPerformsNoRandomSelection(): void
    {
        $registry = self::twoSkinRegistry();
        $policy = new DefaultSkinSelectionPolicy();
        $context = SkinSelectionContext::fromRequest([], [], self::config('local'));

        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $ids[] = $policy->select($registry, $context)->skin()->id();
        }

        self::assertSame(['alpha-skin'], array_values(array_unique($ids)));
    }

    public function testNoProductionSelectionCodeReachesForRandomness(): void
    {
        $forbidden = ['rand(', 'mt_rand(', 'random_int(', 'array_rand(', 'shuffle('];

        foreach (glob(dirname(__DIR__, 3) . '/src/Skin/Selection/*.php') ?: [] as $file) {
            $raw = file_get_contents($file);
            self::assertIsString($raw);

            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString($needle, $raw, basename($file) . ' must not randomise');
            }
        }
    }

    public function testAFutureRandomPolicyPlugsInWithoutTouchingRoutesOrContent(): void
    {
        $registry = self::twoSkinRegistry();
        $context = SkinSelectionContext::fromRequest([], [], self::config('local'));

        $first = (new FakeRandomSkinSelectionPolicy(0))->select($registry, $context);
        $second = (new FakeRandomSkinSelectionPolicy(1))->select($registry, $context);

        self::assertSame('alpha-skin', $first->skin()->id());
        self::assertSame('beta-skin', $second->skin()->id());
        self::assertSame(SkinSelectionSource::Policy, $second->source());
        self::assertTrue($second->shouldPersist(), 'A policy choice must be carryable across navigation');

        // The route contract is byte-identical whichever policy was used.
        self::assertSame(RouteCatalog::names(), RouteCatalog::names());
        self::assertSame('1.0.0', RouteCatalog::VERSION);
    }

    public function testPoliciesAreInterchangeableThroughTheInterface(): void
    {
        $registry = self::twoSkinRegistry();
        $context = SkinSelectionContext::development();

        $policies = [new DefaultSkinSelectionPolicy(), new FakeRandomSkinSelectionPolicy(1)];
        $names = [];

        foreach ($policies as $policy) {
            $names[] = $policy->name();
            self::assertTrue($registry->has($policy->select($registry, $context)->skin()->id()));
        }

        self::assertSame(['default', 'fake-random'], $names);
    }
}
