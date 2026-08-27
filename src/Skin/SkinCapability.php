<?php

declare(strict_types=1);

namespace Facet\Skin;

/**
 * What a skin is able to contribute to a request.
 *
 * Capabilities exist so shared runtime code can branch on what a skin offers
 * without knowing which skin it is holding. Every case must be observable from
 * the outside (a view directory, a manifest entry) rather than self-declared
 * marketing: a capability nothing can verify is not worth declaring.
 */
enum SkinCapability: string
{
    /** Provides server-rendered templates for logical view identifiers. */
    case ServerRenderedViews = 'server-rendered-views';

    /** Ships a module entrypoint that decorates markup PHP already sent. */
    case ProgressiveEnhancement = 'progressive-enhancement';

    /** Ships its own stylesheet, separate from the shared base stylesheet. */
    case IsolatedStylesheet = 'isolated-stylesheet';
}
