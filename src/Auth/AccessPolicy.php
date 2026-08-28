<?php

declare(strict_types=1);

namespace Facet\Auth;

use Facet\Account\Account;
use Facet\Account\Role;
use Facet\Routing\RouteCatalog;
use Facet\Routing\Visibility;

/**
 * The one place that decides who may reach what.
 *
 * It is a pure function of two values — the route's declared visibility and the
 * principal resolved from the stored account — which is why it can be asserted
 * as a complete truth table rather than sampled through requests. Nothing here
 * reads a request field, a cookie, a header or a template variable: the only
 * role that exists at this point is the one {@see Authenticator} read out of
 * the database on this request.
 *
 * There is no role hierarchy. An admin is refused the client area exactly as a
 * client is refused the admin area, and that symmetry is intentional: a client
 * area whose contents an administrator can browse by URL is not a client area,
 * and privilege in one direction has a way of quietly becoming privilege in
 * both.
 */
final class AccessPolicy
{
    /**
     * @param Account|null $account the principal for this request, if any
     */
    public static function decide(Visibility $visibility, ?Account $account): AccessDecision
    {
        // A disabled account is never a principal — the authenticator refuses to
        // return one — but asserting it here as well means the policy stays
        // correct in isolation, whatever hands it an account.
        if ($account !== null && !$account->isActive()) {
            $account = null;
        }

        return match ($visibility) {
            Visibility::Public => AccessDecision::Allow,
            Visibility::Guest => $account === null
                ? AccessDecision::Allow
                : AccessDecision::AlreadyAuthenticated,
            Visibility::Authenticated => $account === null
                ? AccessDecision::Authenticate
                : AccessDecision::Allow,
            Visibility::Admin => self::forRole($account, Role::Admin),
            Visibility::Client => self::forRole($account, Role::Client),
        };
    }

    /**
     * The route name an account's own area is served by.
     *
     * Used for both halves of the same idea: where a successful login lands,
     * and where an authenticated visitor who asked for the login form is sent
     * instead. Returning a route *name* rather than a path keeps the catalog
     * the only place a URL is written down.
     */
    public static function homeRouteFor(Role $role): string
    {
        return match ($role) {
            Role::Admin => RouteCatalog::ADMIN_DASHBOARD,
            Role::Client => RouteCatalog::CLIENT_AREA,
        };
    }

    public static function homePathFor(Role $role): string
    {
        return RouteCatalog::get(self::homeRouteFor($role))->path();
    }

    private static function forRole(?Account $account, Role $required): AccessDecision
    {
        if ($account === null) {
            return AccessDecision::Authenticate;
        }

        return $account->is($required) ? AccessDecision::Allow : AccessDecision::Forbid;
    }
}
