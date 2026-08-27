<?php

declare(strict_types=1);

namespace Facet\Routing;

/**
 * Where a route's data comes from.
 *
 * Naming the source in the contract keeps rendering independent from storage:
 * a template consumes a route's declared source without knowing how it is
 * implemented.
 */
enum DataSource: string
{
    /** Versioned canonical content shipped with the repository. */
    case ContentCorpus = 'content-corpus';

    /** Messages submitted through the contact form. */
    case MessageStore = 'message-store';

    /** The authentication session / current principal. */
    case AuthSession = 'auth-session';

    /** The route needs no data beyond its own template. */
    case None = 'none';
}
