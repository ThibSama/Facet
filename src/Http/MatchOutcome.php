<?php

declare(strict_types=1);

namespace Facet\Http;

/**
 * Why a request did or did not reach a route.
 */
enum MatchOutcome: string
{
    case Matched = 'matched';

    /** No declared route owns this path. */
    case NotFound = 'not-found';

    /** A declared route owns this path but not with this method. */
    case MethodNotAllowed = 'method-not-allowed';
}
