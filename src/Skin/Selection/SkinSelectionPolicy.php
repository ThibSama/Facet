<?php

declare(strict_types=1);

namespace Facet\Skin\Selection;

use Facet\Skin\SkinRegistry;

/**
 * Decides which registered skin renders a request.
 *
 * The seam exists so selection strategy can change — a per-visitor random
 * skin, an A/B split, a stored preference — without routing or content
 * knowing. Implementations must be total: every context yields a skin, so
 * there is no request shape that leaves the runtime without one.
 */
interface SkinSelectionPolicy
{
    public function select(SkinRegistry $registry, SkinSelectionContext $context): SkinSelection;

    /** Stable identifier for the strategy, for logging and tests. */
    public function name(): string;
}
