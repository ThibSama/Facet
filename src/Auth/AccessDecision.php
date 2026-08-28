<?php

declare(strict_types=1);

namespace Facet\Auth;

/**
 * What the policy concluded about one request against one route.
 *
 * Four outcomes, and they are deliberately not two. "Nobody is signed in" and
 * "someone is signed in but this is not theirs" must not collapse into a single
 * refusal: the first is answered by sending the visitor somewhere they can fix
 * it, and the second by refusing outright, because there is nothing a client
 * can do at a login form to become an administrator.
 *
 * The enum carries no HTTP. Which status a refusal wears and where a redirect
 * points are decisions {@see \Facet\Http\AccessGuard} makes, which is what
 * keeps the policy itself assertable as a truth table.
 */
enum AccessDecision
{
    /** The request may proceed to the handler. */
    case Allow;

    /** Nobody is signed in, and this route requires that somebody is. */
    case Authenticate;

    /** Somebody is signed in, and this route is not theirs. */
    case Forbid;

    /** Somebody is signed in, and this route is for guests only. */
    case AlreadyAuthenticated;
}
