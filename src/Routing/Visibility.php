<?php

declare(strict_types=1);

namespace Facet\Routing;

/**
 * Who a route is reachable by.
 *
 * This is a machine-testable declaration, and since PORT-93 it is also the
 * input the central guard reads: {@see \Facet\Auth\AccessPolicy} turns one of
 * these cases plus the resolved principal into an access decision, before any
 * protected handler runs. The enum stays free of HTTP and of roles-as-objects —
 * it declares *what a route requires*, and the policy decides what to do about
 * it.
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

    /**
     * Requires an authenticated principal holding the client role.
     *
     * Declared separately from {@see self::Authenticated} because "any signed-in
     * person" and "a client" are different permissions, and the client area is
     * the second. An admin is not a client with extra powers: they see a
     * different area, and the two are mutually exclusive by design rather than
     * by hierarchy. A hierarchy would make every future client-only surface
     * silently visible to administrators, which is the opposite of what a
     * client area is for.
     */
    case Client = 'client';

    public function requiresAuthentication(): bool
    {
        return $this === self::Authenticated || $this === self::Admin || $this === self::Client;
    }

    /**
     * Whether the visibility names one specific role rather than any principal.
     */
    public function isRoleSpecific(): bool
    {
        return $this === self::Admin || $this === self::Client;
    }

    /**
     * Public and guest routes are indexable by crawlers; protected ones are not.
     */
    public function isPubliclyReachable(): bool
    {
        return $this === self::Public || $this === self::Guest;
    }
}
