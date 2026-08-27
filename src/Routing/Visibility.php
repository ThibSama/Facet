<?php

declare(strict_types=1);

namespace Facet\Routing;

/**
 * Who a route is reachable by.
 *
 * This is a machine-testable declaration, not documentation: authorisation
 * behaviour is out of scope here, but the contract each future dispatcher must
 * honour is expressed as data.
 */
enum Visibility: string
{
    /** Reachable by anyone, authenticated or not. */
    case Public = 'public';

    /** Reachable only when NOT authenticated (e.g. the login form). */
    case Guest = 'guest';

    /** Requires an authenticated principal of any role. */
    case Authenticated = 'authenticated';

    /** Requires an authenticated principal holding the admin role. */
    case Admin = 'admin';

    public function requiresAuthentication(): bool
    {
        return $this === self::Authenticated || $this === self::Admin;
    }

    /**
     * Public and guest routes are indexable by crawlers; protected ones are not.
     */
    public function isPubliclyReachable(): bool
    {
        return $this === self::Public || $this === self::Guest;
    }
}
