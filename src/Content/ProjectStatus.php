<?php

declare(strict_types=1);

namespace Facet\Content;

/**
 * Where a project stands. Kept factual: no marketing states.
 *
 * A lifecycle state is an *editorial* claim about intent — that a scope was
 * reached, or that maintenance was deliberately stopped. Repository metadata
 * cannot establish it: an inactive repository, a superseding project or an old
 * last-push date are silence, not evidence. When no canonical source states the
 * lifecycle, {@see self::Unspecified} is the honest answer.
 */
enum ProjectStatus: string
{
    /** Active development is happening now. */
    case InProgress = 'in-progress';

    /** Reached its intended scope and is no longer actively developed. */
    case Completed = 'completed';

    /** Kept for the record; superseded or no longer maintained. */
    case Archived = 'archived';

    /**
     * No canonical source states where the project stands.
     *
     * This is a declared absence of evidence, never a soft synonym for
     * "finished" or "abandoned". Presentations must render it as unknown or
     * omit the status entirely — never resolve it to one of the other cases.
     */
    case Unspecified = 'unspecified';

    public function isActive(): bool
    {
        return $this === self::InProgress;
    }

    /**
     * Whether a canonical source substantiates this lifecycle state.
     */
    public function isSubstantiated(): bool
    {
        return $this !== self::Unspecified;
    }
}
