<?php

declare(strict_types=1);

namespace Facet\Tests\Support;

use Facet\Skin\Selection\SkinSelection;
use Facet\Skin\Selection\SkinSelectionContext;
use Facet\Skin\Selection\SkinSelectionPolicy;
use Facet\Skin\Selection\SkinSelectionSource;
use Facet\Skin\SkinRegistry;

/**
 * A stand-in for a future Random policy.
 *
 * It exists only to prove the seam: a strategy that ignores the request
 * entirely and picks a skin by its own rule plugs into the same interface,
 * with no change to routes, content or the shared runtime. It is seeded so the
 * test asserting it is deterministic — real randomness is out of scope for the
 * MVP and is deliberately absent from src/.
 */
final class FakeRandomSkinSelectionPolicy implements SkinSelectionPolicy
{
    private int $seed;

    public function __construct(int $seed = 0)
    {
        $this->seed = $seed;
    }

    public function name(): string
    {
        return 'fake-random';
    }

    public function select(SkinRegistry $registry, SkinSelectionContext $context): SkinSelection
    {
        $skins = $registry->all();

        return SkinSelection::of($skins[$this->seed % count($skins)], SkinSelectionSource::Policy);
    }
}
