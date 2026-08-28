<?php

declare(strict_types=1);

namespace Facet\Http;

use Facet\Account\Account;
use Facet\Auth\AccessDecision;
use Facet\Auth\AccessPolicy;
use Facet\Auth\Authenticator;
use Facet\Routing\HttpMethod;
use Facet\Routing\RouteCatalog;
use Facet\Routing\RouteDefinition;
use Facet\Routing\Visibility;
use Facet\Security\CsrfGuard;
use Facet\Session\Session;

/**
 * The gate every dispatched request passes through, before any handler runs.
 *
 * It is placed between routing and dispatch on purpose, and that placement is
 * the security property. A check inside a handler protects the handler that
 * remembered to make it; a check in a template protects nothing at all, since
 * the handler has already run, the data has already been read and the only
 * thing a hidden section hides is the pixels. Here, a route's declared
 * visibility is enforced whether or not anyone has written its handler yet —
 * which is why an anonymous visitor never reaches even a private page's data.
 *
 * Two rules are applied, in this order:
 *
 * 1. **Access.** {@see AccessPolicy} decides, from the route's visibility and
 *    the account resolved out of the database this request. Anonymous requests
 *    for a protected route are sent to the login form; a signed-in visitor
 *    asking for a route belonging to the other role is refused outright.
 * 2. **Intent.** Every POST to a non-public route must carry this session's
 *    CSRF token. It is enforced centrally rather than per handler, so a future
 *    admin mutation is defended by existing in the catalog rather than by its
 *    author remembering. Logout and admin message status changes both pass
 *    through this same boundary.
 *
 * Access comes first: an anonymous POST to a protected route is answered as
 * unauthenticated rather than as a token failure, because that is what it is.
 * The public contact form keeps its own token check — it is a public route, so
 * rule 2 does not reach it, and the order in which it interleaves validation,
 * throttling and storage is part of that handler's design.
 */
final class AccessGuard
{
    private Authenticator $authenticator;

    private CsrfGuard $csrf;

    private Session $session;

    public function __construct(Authenticator $authenticator, Session $session, ?CsrfGuard $csrf = null)
    {
        $this->authenticator = $authenticator;
        $this->session = $session;
        $this->csrf = $csrf ?? new CsrfGuard();
    }

    /**
     * The response that must be sent *instead* of dispatching, or null when the
     * request may proceed.
     *
     * @throws HttpException when the request is refused outright
     */
    public function guard(RouteDefinition $route, Request $request): ?Response
    {
        $visibility = $route->visibility();

        // A public route resolves no principal at all. That keeps the public
        // site's promise literal — a GET of a public page issues no query — and
        // it means a database outage cannot take down the portfolio.
        if ($visibility === Visibility::Public) {
            return null;
        }

        $account = $this->authenticator->current();
        $decision = AccessPolicy::decide($visibility, $account);

        switch ($decision) {
            case AccessDecision::Authenticate:
                // Where a visitor can do something about it. Deterministic: the
                // same target for every protected route, with no `?next=`
                // parameter — an attacker-suppliable redirect target is a
                // separate liability, and nothing here needs one yet.
                return Response::redirect(
                    RouteCatalog::get(RouteCatalog::LOGIN)->path(),
                    Response::STATUS_SEE_OTHER
                );

            case AccessDecision::AlreadyAuthenticated:
                // Guest-only, and this visitor is not a guest. They are sent to
                // their own area rather than shown a form that could only sign
                // them in as somebody else.
                return Response::redirect(
                    AccessPolicy::homePathFor($this->requireAccount($account)->role()),
                    Response::STATUS_SEE_OTHER
                );

            case AccessDecision::Forbid:
                // 403 and not 404: this visitor is known, and the honest answer
                // is that the route is not theirs. It is also not a redirect —
                // there is nowhere to send someone whose own role is the reason
                // they were refused.
                throw HttpException::forbidden(sprintf(
                    'Route "%s" is not permitted for this account.',
                    $route->name()
                ));

            case AccessDecision::Allow:
                break;
        }

        $this->requireIntent($route, $request);

        return null;
    }

    /**
     * The CSRF rule for private mutations.
     *
     * Scoped to POST, because that is the only method the catalog models for a
     * mutation; a route that later accepts another unsafe method is covered by
     * widening this one condition, in this one file.
     */
    private function requireIntent(RouteDefinition $route, Request $request): void
    {
        if (!$request->isMethod(HttpMethod::Post)) {
            return;
        }

        if (!$this->csrf->isValid($this->session, $request->bodyParam(CsrfGuard::FIELD))) {
            throw HttpException::forbidden(sprintf(
                'The submission to "%s" carried no valid CSRF token.',
                $route->name()
            ));
        }
    }

    /**
     * Narrows a principal the decision already proved is present.
     *
     * `AlreadyAuthenticated` cannot be reached with a null account, so this is
     * an assertion rather than a branch — stated in code because "cannot" and
     * "does not" are different claims, and only one of them is checked.
     */
    private function requireAccount(?Account $account): Account
    {
        if ($account === null) {
            throw HttpException::internal('An authenticated decision carried no account.');
        }

        return $account;
    }
}
