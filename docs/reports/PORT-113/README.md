# PORT-113 — the Phase 5 publication candidate, as measured

This directory holds the end-to-end evidence and consolidates the four gates
Phase 5 is made of. Nothing here is a self-acceptance: the gates are recorded so
a human can read them, contradict them, or re-run them.

Everything below comes from the three Playwright JSON reports beside this file.
`tests/Unit/Publication/E2eGateEvidenceTest.php` re-derives the counts, the
engine list and the retry configuration from those same files on every
`composer test`, so the prose here cannot drift away from the runs without the
suite failing — the arrangement PORT-111 established for the Lighthouse gate.

## What was run

| | |
|---|---|
| Tested commit | `076c40971d0b28d502b738cc62c3e8b06f7e6757` plus the PORT-59 changes committed with this report |
| Worktree | clean, `HEAD == origin/main` at measurement time |
| Production build digest | `eee0af588dc3aa12a4abcdb61ee5ac7d60415565c5a381099e331dde687da8f3` |
| Transport | `http://127.0.0.1:8788` (`php -S`, the real `public/index.php` router) |
| `APP_URL` (canonical origin) | `https://facet.thibaultpaul.com` |
| `APP_ENV` | `production` |
| Database | `facet_test` on MariaDB 11.4.13, schema built from `database/migrations/` |
| Date | 2026-08-28, 23:30–23:36 CEST (21:30–21:36 UTC) |

### Tooling

| | |
|---|---|
| Playwright | 1.62.1 (`@playwright/test`, pinned exactly) |
| Chromium | 151.0.7922.34 |
| Firefox | 153.0 |
| WebKit | 26.5 |
| Node | v24.18.0 |
| PHP | 8.5.4 (cli-server SAPI) |
| MariaDB | 11.4.13 |
| OS | Linux 7.0.0-30-generic x86_64 |

The browser binaries were already installed on this machine. The only package
added for this checkpoint is `@playwright/test@1.62.1`, pinned to an exact
version rather than a range, so a future `npm install` cannot silently change
which harness produced these numbers.

## PORT-112 — the suite, and the Chromium gate

### How it is isolated

Three mechanisms, and they are the reason the suite is reorderable rather than
merely green today.

1. **The database is returned to a known state before every test.**
   `tests/E2E/fixtures/seed.php` truncates `contact_messages` and `users` and
   re-seeds them. TRUNCATE resets `AUTO_INCREMENT`, so the seeded messages carry
   ids 1, 2 and 3 in *every* test — which is what lets a test assert
   `/admin/messages?id=1` without depending on what ran before it.
2. **Every test gets its own browser context**, and therefore its own cookie
   jar and its own PHP session. No test inherits another's sign-in, CSRF token,
   contact flash or rate-limit window.
3. **One worker, no parallelism.** One server and one schema are shared by the
   run; two tests at once would observe each other's rows.

The schema itself is built once per run, in `globalSetup`, by dropping every
table and applying the project's own migrations — so a run starts from
`database/migrations/`, not from whatever a previous run left behind.

Nothing writes SQL from TypeScript. The fixture is the single definition of a
known state, and it refuses to run at all unless `FACET_TEST_DB_DSN` names the
`facet_test` schema. A missing database stops the run rather than skipping it.

The reset is not asserted by assertion of intent: `fixture-integrity.spec.ts`
submits a contact message and changes a message's status *through the product*,
reads the rows back, resets, and requires the state to be identical down to the
ids.

### The scenario matrix

43 cases. Every one runs on every engine.

| Area | Cases | What is covered |
|---|---:|---|
| `public-navigation.spec.ts` | 6 | Home, the primary navigation landmark and its `aria-current`, every section reachable by its own link, the brand link, the skip link handing the keyboard to `<main>`, About, and a 404 that is still a working page |
| `projects.spec.ts` | 5 | Index against the corpus (every documented project, one article each, canonical `/projects/{slug}` hrefs), index → detail, every case study by direct URL, an unknown slug, a malformed slug |
| `contact.spec.ts` | 4 | Valid submission → Post/Redirect/Get, confirmation, one-shot flash; the stored message found in the admin inbox; server-side validation failure (422) with values and reasons preserved and `aria-invalid` set; an invalid CSRF token refused (403) with nothing written |
| `auth.spec.ts` | 6 | Admin and client sign-in landing in different areas, wrong password (422), unknown address answered identically, sign-out ending the session, an already-authenticated visitor redirected away from the form |
| `authorization.spec.ts` | 7 | Anonymous requests for `/admin`, `/admin/messages`, `/client` sent to sign in; admin refused the client area (403); client refused both admin routes (403); a private mutation without this session's token refused, leaving the session intact |
| `admin-inbox.spec.ts` | 7 | Dashboard → inbox, the listing with sender and state, a message on its own URL, a status change proved on the detail *and* the list *and* a fresh read, the other messages untouched, an unknown id (404), a malformed id (400) |
| `client-area.spec.ts` | 1 | The client shell names its account and can be signed out of |
| `progressive-enhancement.spec.ts` | 5 | The served document ships both controls hidden and a complete navigation; the theme control switches `data-theme` and is remembered across a reload; the collapse below 48em, opened by its button and closed by Escape with focus returned; above the breakpoint the control is gone and the list always shown; a narrow visitor still reaches every section |
| `fixture-integrity.spec.ts` | 2 | The seeded state matches what the assertions describe; a reset undoes a real mutation, ids included |

Assertions address roles and accessible names — `getByRole('navigation', { name:
'Primary' })`, `getByLabel('Status')` — rather than classes. A selector tied to
markup would keep passing through a restyle that broke the page for a screen
reader, which is the regression worth catching. There are two deliberate
exceptions, both noted in place: the CSRF hidden input, which has no accessible
name because it is not for a person, and the navigation toggle above the
breakpoint, which the stylesheet removes from the page so it has no role left.

Every test also fails if the page produced an uncaught exception, an unhandled
promise rejection, a console error, or a subresource the server refused.
Rejections are collected by an init script rather than read out of console text,
because the three engines neither phrase nor route them identically.

**Result.** The full suite, green on Chromium twice consecutively, with retries
configured to 0 — a retried pass would not be this gate:

| Run | Started (UTC) | Cases | Passed | Failed | Flaky | Skipped | Wall |
|---|---|---:|---:|---:|---:|---:|---:|
| 1 | 21:30:42 | 43 | 43 | 0 | 0 | 0 | 54.5 s |
| 2 | 21:31:37 | 43 | 43 | 0 | 0 | 0 | 55.1 s |

Both runs covered the same 43 case titles; the evidence test compares the two
lists rather than only the two counts.

A third run was made with the spec files given in reverse order — 43 passed —
as a direct check that nothing depends on execution order.

## PORT-113 — three engines

The same 43 cases, one run, no engine-specific disabling and no exception
claimed. Started 21:32:38 UTC, 247.7 s wall.

| Engine | Version | Cases | Passed | Failed | Flaky | Skipped | Wall |
|---|---|---:|---:|---:|---:|---:|---:|
| Chromium | 151.0.7922.34 | 43 | 43 | 0 | 0 | 0 | 52.2 s |
| Firefox | 153.0 | 43 | 43 | 0 | 0 | 0 | 68.4 s |
| WebKit | 26.5 | 43 | 43 | 0 | 0 | 0 | 100.6 s |
| **Total** | | **129** | **129** | **0** | **0** | **0** | |

**Console and page errors: none, on any engine.** No test recorded a console
error, an uncaught exception, an unhandled rejection or a refused subresource.

**Compatibility defects found: none.** That is a weaker claim than it sounds and
is worth stating honestly rather than presenting as a triumph. Almost every page
in this suite is HTML the server composed, which the three engines render from
the same bytes; the only genuinely engine-sensitive surface is the pair of
enhanced controls, and `progressive-enhancement.spec.ts` exists to put
`matchMedia`, `localStorage`, the `hidden` property and a media-query listener
under all three. They agreed. No assertion was weakened, and no case was
narrowed, to reach that.

Two assertions were adjusted while the suite was being written, both before any
engine had run twice, and neither was a compatibility exception:

- The skip link is off-screen until focused, by design, so it is reached with
  Tab and Enter rather than clicked. All three engines then move focus to
  `<main>`, and the suite asserts that focus rather than settling for
  visibility.
- Chromium logs `Failed to load resource` for a 404 *document*; Firefox does
  not. Keeping that line would have meant the 404 page failed its own test in
  one engine and passed in two. It is dropped, and replaced by something
  stricter and engine-independent: every non-navigation response with a status
  of 400 or above fails the test. A stylesheet, script or font the server
  refused is a defect whatever the console decided to say about it.

## The build did not change

PORT-112 adds test tooling, so the question is whether the thing being published
moved. It did not.

```
rm -rf public/build && npm run build
find public/build -type f | LC_ALL=C sort | xargs sha256sum | sha256sum
→ eee0af588dc3aa12a4abcdb61ee5ac7d60415565c5a381099e331dde687da8f3
```

That is byte-for-byte the digest PORT-109 recorded and PORT-111 audited. The
production output at this commit is the *same* output the accepted SEO,
accessibility and performance gates measured, so those gates still describe this
candidate and there is nothing to re-measure.

Consequently **Lighthouse was not re-run.** PORT-111's three runs stand. Re-running
them would have produced different numbers for a build that had not changed,
which is exactly the "prettier numbers" that must not be manufactured.

Why the digest is unchanged, concretely: `@playwright/test` is a devDependency
that no Vite entrypoint imports, the E2E specs live outside `tsconfig.json`'s
`include` and outside every Rollup input, and nothing under `src/`,
`resources/`, `content/`, `config/` or `public/` was touched.

## Phase 5 evidence, consolidated

All four gates describe one publication candidate: commit
`076c4097…` + this checkpoint's test-only changes, production build digest
`eee0af58…`.

| Gate | Checkpoint | Where the evidence lives | Result |
|---|---|---|---|
| SEO (server-side metadata, sitemap, robots, canonical) | PORT-56 | `docs/decisions/PORT-56-server-side-seo.md`; `tests/Smoke/SeoMetadataHttpTest.php`, `tests/Smoke/SeoInfrastructureHttpTest.php`, `tests/Unit/Seo/` | pass (accepted) |
| Accessibility + Lighthouse | PORT-111 | `docs/reports/PORT-111/` (3 raw reports), `tests/Unit/Publication/LighthouseGateEvidenceTest.php` | pass, median 100 a11y / 100 SEO / 97 perf (accepted) |
| Performance and bundle budgets | PORT-109, PORT-111 | `docs/decisions/PORT-109-bundle-and-transport.md`; `tests/Unit/Asset/BundleBudgetTest.php`; the same three Lighthouse reports | pass (accepted) |
| End-to-end, three engines | PORT-112, PORT-113 | this directory; `tests/E2E/`; `tests/Unit/Publication/E2eGateEvidenceTest.php` | 129/129 pass |

The E2E suite does not replace the lower-level gates and does not try to. The
no-JavaScript contract, skin isolation, template safety, error disclosure, the
authorisation truth table and the SEO document contents are asserted where they
can be asserted completely — in PHPUnit, against the served document — and the
browser suite asserts the half only a browser can: that a person can actually
complete these journeys, in three engines, and that the enhancement layer does
what it claims once it is running.

## Reproducing this

```bash
rm -rf public/build && npm run build
find public/build -type f | LC_ALL=C sort | xargs sha256sum | sha256sum  # expect eee0af58…
npm run e2e:chromium     # PORT-112: run it twice, expect 43 passed both times
npm run e2e              # PORT-113: expect 129 passed across three engines
composer test            # re-derives the counts in this file from the JSON beside it
```

`.env` is not committed and none is needed: the suite boots its own server with
an explicit environment (`APP_ENV=production`, `APP_URL=https://facet.thibaultpaul.com`,
`APP_DEBUG=false`, a throwaway `APP_KEY`) and points the application's `DB_*` at
the `FACET_TEST_DB_*` credentials from `.env.testing`. The three browser
binaries must already be installed; if any is missing the run stops.

## One limitation, stated plainly

The E2E TypeScript is linted by ESLint but is **not** type-checked by
`npm run typecheck`. `tsconfig.json` targets browser code, and the specs import
Node builtins (`node:fs`, `node:child_process`, `process`), which would require
adding `@types/node` — outside this checkpoint's package authority. The specs
are transpiled and executed on three engines every run, so a type error surfaces
as a failing test rather than passing silently; extending the type-check
properly is a one-package change for whoever holds that authority.

## Status

`READY_FOR_MANUAL_ACCEPTANCE`. Phase 5 is not self-accepted here.
