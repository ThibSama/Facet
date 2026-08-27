<?php

declare(strict_types=1);

namespace Facet\Skin\Selection;

/**
 * Why a particular skin ended up selected.
 *
 * Carrying the reason alongside the result is what lets a caller decide
 * whether to persist the choice, without the policy having to know about
 * cookies, sessions or any other transport.
 */
enum SkinSelectionSource: string
{
    /** Nothing asked for a skin; the registry default answered. */
    case Default = 'default';

    /** The request explicitly asked for it, and the environment allowed it. */
    case Requested = 'requested';

    /** Carried over from an earlier explicit choice in the same visit. */
    case Persisted = 'persisted';

    /** A policy chose it by its own rule rather than from the request. */
    case Policy = 'policy';

    /**
     * An explicit choice is one worth remembering across navigation; the
     * default is not, because it is what an absent choice already resolves to.
     */
    public function isExplicit(): bool
    {
        return $this !== self::Default;
    }
}
