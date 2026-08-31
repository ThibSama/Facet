# PORT-137 — A bilingual public site

**Status:** implemented; awaiting human linguistic and visual review.
**Supersedes nothing.** Builds on PORT-134/135 (Satoshi Run), PORT-136 (the home
composition) and PORT-138 (the shell and the theme toggle), all accepted. It
changes none of their design decisions; it makes one bounded change to how the
skin *addresses* the home composition, for a reason §9 sets out.

The public portfolio used to mix its languages: the corpus was written in French,
the shell and the SEO titles were written in English, and the two met on every
page. This checkpoint replaces that with a real bilingual architecture — two
canonical languages, both server-rendered, both complete, neither depending on a
line of JavaScript.

---

## 1. Canonical URLs

The language is in the URL, and the URL is authoritative.

```
/fr        /fr/projects   /fr/projects/{slug}   /fr/about   /fr/contact
/en        /en/projects   /en/projects/{slug}   /en/about   /en/contact
```

Every public page carries a `{locale}` route parameter, validated by the same
closed `Locale` enum the application renders in. That is what makes `/de/about` a
routing miss rather than a decision: an unsupported language never reaches a
handler, so nothing downstream has to decide what `de` might have meant. It is a
404, never a redirect to French — canonicalising it would publish an indexable
page claiming to be German.

There is one route definition per page, not one per language. `RouteCatalog`
declares `/{locale}/projects` once; the router validates the segment; the
dispatcher reads the matched parameter. No controller, view or handler is
duplicated per language.

## 2. Legacy routes

`/`, `/projects`, `/projects/{slug}`, `/about` and `/contact` still exist, and
they are no longer pages. Each is an **entry route**: it resolves a preferred
language and answers `302` to the canonical localized URL. They accept `GET`
only — negotiating a language is a safe read, and a submission is posted to the
localized URL the page it was rendered on already names.

`302` rather than `301` because the target depends on a preference the visitor
can change; a permanent redirect would declare one language to be permanently
what `/projects` *means*. Neither the sitemap nor any link the site prints
advertises an unprefixed URL, so there is no duplicate indexable content.

The pairing between an entry route and the localized route it leads to is
declared once, in `RouteCatalog::LOCALIZED_BY_ENTRY`, and is read by the
redirect, the language switch and the sitemap alike.

## 3. Locale resolution

For an unprefixed entry route only, and in this order:

1. a valid `facet_locale` cookie;
2. `Accept-Language`, restricted to the supported set;
3. French.

Nothing else. No IP address, no country lookup, no network call.

**An explicit locale URL always wins.** `/en/projects` renders English whatever
the cookie says and whatever the browser asked for, which is what makes a shared
link mean the same page for the person who sent it and the person who opens it.

`AcceptLanguage` reads language tags and their q-values well enough to choose
between two languages and no further: it matches on the primary subtag (`fr-CA`
is French), respects quality ordering, treats `q=0` as a refusal rather than a
weak preference, breaks ties by header order, and answers "nothing" for a header
that is absent, empty, malformed, over-long or names only unsupported languages.
"Nothing" is read as *fall back*, never as an error. No dependency was added: the
supported set is two languages, and the parser is fifty lines.

## 4. The locale cookie

```
facet_locale=fr|en; Path=/; Max-Age=31536000; SameSite=Lax; HttpOnly[; Secure]
```

**The name has an underscore, and that is not a style choice.** The checkpoint
asked for `facet.locale`; PHP rewrites `.` to `_` in the keys of `$_COOKIE` — a
survival from the register_globals era that still applies — so a cookie actually
named `facet.locale` arrives as `$_COOKIE['facet_locale']`, and a server looking
for the name it set would never find it. The preference would be written on every
request and read on none. It is also what the rest of this application already
does: the session cookie is `facet_session` and the skin preference is
`facet_skin`. The dot is asserted absent in
`tests/Unit/I18n/LocaleResolutionTest.php`, so nobody can quietly put it back.

> This one was found by a browser and not by a unit test, and the reason is worth
> recording: every in-process test hands the application a clean cookie array, so
> the mangling only exists between a real HTTP request and `$_COOKIE`. It was the
> Playwright case for "choosing a language is remembered" that caught it.

One value from a closed set. No identifier, no session, no personal data. An
unknown value is not repaired and not partially matched — a cookie saying `de` or
`fr-CA` simply decides nothing, so a stale or tampered cookie cannot break the
site.

`HttpOnly` because no script on this site reads it: the language is decided on
the server, rendered into the HTML, and carried by the links in the page, so a
client-readable copy could only ever disagree with the page around it. `Secure`
follows the deployment's own `APP_URL` scheme rather than a request header a
client can set, for the same reason canonical URLs do.

It is written only when it would change, so an ordinary page view carries no
`Set-Cookie` it has no reason to. Visiting an explicit localized URL **updates**
the preference: following an `/en/...` link is a clearer statement of intent than
anything a header can say.

Locale and theme are independent. The theme stays in `localStorage` under
`facet.theme`, is never read by the server, and is untouched by this checkpoint;
switching language cannot move it, and switching theme cannot move the language.

> **One defect this surfaced.** `ResponseEmitter` called `header($name, true)`
> for every header, which for `Set-Cookie` *replaces* whatever was set before it.
> The session adapter has already emitted the session cookie by the time a
> Response is emitted, so adding the locale cookie would have dropped the
> session — no CSRF token, no flash, and a contact form that refused every
> submission. `Set-Cookie` is now the one header emitted with `replace: false`.

## 5. Translation architecture

Two sources, split by what the text *is*.

**Interface chrome** lives in `src/I18n/Translations.php`, as one array in which
every entry holds both languages:

```php
'home.selectedWork' => ['fr' => 'Projets sélectionnés', 'en' => 'Selected work'],
```

The shape is the point: "the French catalog has a key the English one does not"
is not a state this file can be in, because adding a string means writing both
halves in the same literal. A `Translator` is bound to a locale at construction
and is the only way a template or a handler obtains a string, so there is no
ambient "current locale" a page could forget to pass.

**Editorial content** stays in `content/*.json`, which remains the single source
of every *fact*, and is overlaid for English by `content/translations/en.json`.

## 6. Facts versus translations

The overlay carries prose and nothing else. A project's slug, name,
technologies, status, dates, links, media source and `featured` flag are stored
once and read identically in both languages; its summary, context, role,
concepts, outcomes, media description and link labels are translated. A skill's
identity and category are facts; its summary is prose. An experience's title,
organisation, location, period and kind are facts; its summary and highlights are
prose.

Three properties make this hold rather than merely describing an intention:

- **One loading path.** `CorpusLoader::load(Locale)` reads the same files and
  runs the same validations whatever the locale, so a language cannot acquire a
  different set of projects, dates or technologies than another.
- **The overlay is total.** Every entry and every localizable field must be
  present, or the corpus fails to load with the missing path named. "The English
  site is complete" is checked by the build, not claimed by somebody once.
- **Lists must match in length.** A translated `concepts`, `outcomes`,
  `highlights` or link-label list must have exactly as many items as the
  canonical one. That is the check that stops a translation from adding a claim
  the corpus does not make — the one failure mode that would turn a translation
  into a second source of truth.

The canonical locale has no file at all: French *is* the corpus, and
`TranslationOverlay::canonical()` is the identity overlay.

### Deliberately not translated

Proper nouns stay as they are: **Facet**, **Kushim**, **Scora**, **Biogazen**,
**Eszter**, **Math L'home**, **Satoshi Run**, every technology name, and the
official names of French qualifications and certifications — *Bachelor
Concepteur Développeur d'Applications*, *BTS SIO option SLAM*, *DCG — Diplôme de
Comptabilité et de Gestion*, *Baccalauréat STMG option Gestion et Finance*,
*Certification Pix*, *Atelier RGPD de la CNIL* — together with the institutions
that award them and the places they are in. Rendering an official qualification
under an invented English name would state a credential that does not exist. The
prose *around* them is fully translated, and this is flagged for the human
review.

### One thing that changed for French

`in-progress`, `language`, `education` and their siblings are stored machine
values, and the shell used to print the value itself. That was English on a
French page and an implementation token on both, so each case now has a display
name in the catalog — *En cours* / *In progress*, *Langages* / *Languages*,
*Formation* / *Education*. The stored values are untouched. This is the only
visible change to French text that is not a translation, and it was required by
the no-mixed-interface rule.

## 7. The missing-translation policy

There is no runtime fallback, and that is the policy rather than an omission.

- A key absent from the catalog raises `MissingTranslationException`. It is never
  printed as itself, so no visitor can be shown `home.selectedWork`.
- It is never answered in the other language either, so a French page cannot
  quietly acquire an English heading because one entry was forgotten.
- Completeness is enforced ahead of any of that: `TranslationCompletenessTest`
  reads every literal key out of `src/` and `resources/skins/`, checks it is
  declared, checks every entry has both languages non-empty, checks the two
  languages are genuinely different catalogs, and checks that a key's
  placeholders are declared identically in both — an unfilled `{max}` on a page
  is the same class of defect as a raw key.
- `ErrorPresenter` is the one exception, and a bounded one: its second rule is
  that reporting an error cannot fail, so it reads the catalog directly rather
  than through the translator, and degrades to a two-sentence last resort in the
  right language if the catalog itself is what broke.

## 8. SEO

Every localized page is canonical to itself — `/en/projects` canonicals to
`/en/projects`, never to `/fr/projects` and never to `/projects`. Alternates are
emitted for both languages plus `x-default`, which points at the **French** URL:
French is the language the corpus is written in and the language an unprefixed
entry falls back to, so it is the deterministic answer to "this page, language
unspecified" rather than a duplicate of one of the two.

Titles, descriptions, `og:locale` and `og:locale:alternate` are localized. The
JSON-LD keeps its canonical facts and points its URLs at the language being
rendered. Origin comes from the existing `APP_URL` contract, never from a Host
header; with no valid origin configured a page still renders, and simply carries
no canonical and no alternates — advertising a counterpart it cannot address
would be worse than advertising none.

The sitemap lists every page in both languages, each `<url>` carrying its
`xhtml:link` alternates including `x-default`, and lists no unprefixed URL at
all. `robots.txt` is unchanged.

## 9. Styling against a route, not a URL

Changing the home page's address from `/` to `/fr` and `/en` broke a large part
of the accepted PORT-136 composition, and it did so silently.

The skin scoped roughly two dozen rules to `body[data-route='/']` — the home
grid, the section rhythm, the hero visual's material, the skills band's surface,
the ribbons, the chips, and the finale's inked plate together with the
`overflow: hidden` that clips it — plus `body[data-route^='/projects/']` for a
case study and `body[data-route='/admin']` and friends for the private shell.
None of those selectors matched any more. The home page rendered with its bands
unpainted and its finale unclipped, which is also what was pushing the document
27px wider than the viewport at 390px.

The fix was not to teach the selectors about language prefixes. It was to stop
styling against the URL at all. The layout now emits a second hook beside
`data-route`:

```html
<body class="facet-body" data-route="/fr" data-facet-route="home">
```

`data-route` is still the address this document was served at — a debugging aid,
exactly as specific as the address bar. `data-facet-route` is the route's
*identity*, named by the canonical catalog, and it is what the skin styles
against. A URL is a spelling, and since PORT-137 every public page has two of
them; a route name never moves. `projects.show` is one selector rather than
`^='/fr/projects/'` and `^='/en/projects/'`, and it stays correct through any
future URL change as well.

This is the one bounded integration change PORT-137 makes to accepted work, and
it makes the accepted work more durable rather than less.

## 10. The language switch

A `<nav aria-label="Language">` holding two links, in the header, between the
brand and the two enhanced controls. It is the one control in the shell that
never ships `hidden` and needs no script at all: changing language is navigation
to another canonical URL, so that is exactly what it is.

Each link says its language twice — the visible mark is `FR` / `EN`, and the
language's own name for itself follows it in a clipped span, so the accessible
name is "FR Français" and *contains* the visible label. The language in effect
carries `aria-current="true"` (not `page`: the primary navigation already says
which page you are on) and stays a real link, because it is the canonical URL of
the page you are on and a disabled control there is one more thing that cannot be
copied, shared or opened in a new tab.

The destination is the counterpart of the page being rendered whenever one
exists, and the localized home when it does not — which is the honest answer on a
404. The query string travels; the skin-override parameter does not, because a
development-only presentation switch is not part of a page's identity. A fragment
cannot travel: it never reaches the server, and no JavaScript was added to
pretend otherwise.

**Placement was measured, not guessed.** `tools/i18n-shots.mjs` records the
header's geometry at 320, 390, 412, 768, 834, 1024, 1280, 1512 and 1920 in both
languages: the header row fits at every one of them, no two controls' boxes
intersect at any of them, and both links are 44px tall — the same square the
collapse control and the theme toggle are given. The switch therefore stays
permanently visible in the header rather than moving into the collapsed menu.
See `docs/reports/PORT-137/measurements.txt`.

## 11. Satoshi Run

Gameplay, physics, the renderers, the world, the obstacles, the scoring, the
local best, the context-loss handling, the deferred chunk and the five-click
contract are untouched.

Only the run's presentation chrome is localized — *Sauter*, *Se baisser*,
*Recommencer*, *Fermer*, *Score*, *Record*, the ready hint and the game-over
line. The game is a deferred chunk and cannot ask the server for anything, so the
labels travel with the document as one `data-facet-run-labels` attribute on the
element the gesture is already attached to, are read once by `enhanceRun`, and
are passed straight into the run's options. `world.ts` knows no language, and
neither does anything else below the mount. A missing or malformed attribute
yields the run's own defaults: a game that will not read its labels is still a
game.

The proper name **Satoshi Run** is the same string in both languages and is not a
label.

## 12. The no-JavaScript contract

With scripts disabled: `/fr` is fully French and `/en` fully English, the
navigation works, the language switch works, `<html lang>` is correct from the
server and is never corrected afterwards, canonical and alternates are correct,
the contact form submits and its refusals come back in the language it was
submitted in, and the footer fallback is in the right language. Primary
localisation depends on no hydration and no DOM replacement.

## 13. Why no dependency

Symfony Translation, or anything like it, would buy plural rules, ICU messages,
catalogue loaders, cache warmers and a compilation step. This site needs two
languages, one flat catalog of chrome, a `{name}` substitution, and one JSON
overlay over a corpus that is already loaded and validated by hand. The whole
i18n layer is seven small classes and adds nothing to the client bundle. A
dependency here would be more code, not less, and would put a framework's
opinions between the corpus and the page.

## 14. Bundle impact

Measured by building with the PORT-137 additions removed and again with them in
place (production build, gzip):

| Asset | Before | After | Δ |
| --- | --- | --- | --- |
| `app.js` (critical) | 2.17 kB / 0.97 kB gz | 2.17 kB / 0.97 kB gz | none |
| `skin-evolving-interface.js` | 7.65 kB / 2.98 kB gz | 7.93 kB / 3.10 kB gz | +0.28 kB / +0.12 kB gz |
| `app.css` | 25.75 kB / 5.97 kB gz | 26.49 kB / 6.06 kB gz | +0.74 kB / +0.09 kB gz |
| `skin-evolving-interface.css` | 58.08 kB / 8.99 kB gz | 59.32 kB / 9.10 kB gz | +1.24 kB / +0.11 kB gz |
| `run.js` (deferred) | 31.19 kB / 12.26 kB gz | 31.20 kB / 12.27 kB gz | +0.01 kB |
| `hero.js` (deferred) | 5.87 kB | 5.87 kB | none |

The critical path is unchanged. The entire client-side cost of PORT-137 is the
skin chunk's label reader (+0.12 kB gzipped) and the language switch's CSS. There
is no i18n runtime, no catalog in the bundle and no translation library: every
string on a page was chosen on the server. `BundleBudgetTest` is unchanged and
passes.

One gate needed a statement rather than a change. `tools/check-font-subset.py`
reads every PHP file for the glyphs the subset must carry, and the run's hint
line — which contains `₿` and `▼` — moved from a TypeScript constant into the
PHP catalog so that it could be said in French. Both characters have always been
drawn by the system fallback stack rather than by Facet Sans, because the subset
is built for the site's *text* and two pictograms inside a deferred overlay are
not worth the bytes on every page load that never opens it. That exception is now
declared in the checker, with its reason, instead of being an accident of which
files the script happened to read. Nothing a visitor sees changed. The checker
also now reads `content/translations/` alongside the corpus.

## 15. Out of scope, and stated so

Admin, login and the client area stay in English: private surfaces are explicitly
not part of this checkpoint. No database migration was added — the locale is a
routing dimension, not a stored one. No third language exists anywhere in the
code. The footer keeps the PORT-138 invariant: its fallback structure is
localized because it is still user-visible with JavaScript off, and it is *not*
restored to view, because it is still duplicate primary navigation. No legal
pages, no social links, no opening cinematics, and nothing touching the future
skin-selection architecture.
