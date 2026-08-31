<?php

declare(strict_types=1);

namespace Facet\I18n;

use Facet\Http\Request;

/**
 * Decides which language an *unprefixed* public entry URL should lead to.
 *
 * The precedence is the whole contract, and it is short:
 *
 * 1. a valid remembered preference;
 * 2. the browser's own `Accept-Language`, restricted to the supported set;
 * 3. French.
 *
 * What is deliberately absent is as important as what is here. A URL that
 * already names a locale never reaches this class: `/en/projects` renders
 * English whatever the cookie or the header says, which is what makes a shared
 * link mean the same thing for the person who sent it and the person who opens
 * it. Nothing below looks at an IP address, a country or a network.
 */
final class LocaleResolver
{
    /**
     * The locale an unprefixed entry route should redirect to.
     */
    public function resolve(Request $request): Locale
    {
        return LocalePreference::read($request->cookies())
            ?? AcceptLanguage::preferred($request->header('accept-language'))
            ?? Locale::default();
    }
}
