<?php

declare(strict_types=1);

namespace Facet\Skin\Selection;

use Facet\Skin\SkinRegistry;

/**
 * The MVP policy: the default skin, unless a development request asks
 * otherwise.
 *
 * There is deliberately no randomness here. Precedence is explicit request,
 * then a choice carried over from earlier navigation, then the registry
 * default — and an id the registry does not know falls through rather than
 * failing the request, because a bad query string should not break a page.
 */
final class DefaultSkinSelectionPolicy implements SkinSelectionPolicy
{
    public function name(): string
    {
        return 'default';
    }

    public function select(SkinRegistry $registry, SkinSelectionContext $context): SkinSelection
    {
        if ($context->allowsOverride()) {
            $requested = $registry->find($context->requestedSkinId());

            if ($requested !== null) {
                return SkinSelection::of($requested, SkinSelectionSource::Requested);
            }

            $persisted = $registry->find($context->persistedSkinId());

            if ($persisted !== null) {
                return SkinSelection::of($persisted, SkinSelectionSource::Persisted);
            }
        }

        return SkinSelection::default($registry->defaultSkin());
    }
}
