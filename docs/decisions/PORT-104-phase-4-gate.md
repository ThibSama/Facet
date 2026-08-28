# PORT-104 — Phase-4 gate: measurements, profiles and budgets

**Status:** recorded — **not self-accepted**
**Date:** 2026-08-28
**Scope:** every rendered route, public and private, after PORT-101, PORT-102
and PORT-103. Nothing here changes content, routing, authentication or
authorisation.

This file records what was measured. Whether Phase 4 passes is not this file's
decision and is not claimed anywhere in it.

---

## What was run

Real Firefox 154.0.1 over `geckodriver` 0.37.1, headless, against the built
production assets served by PHP 8.5.4 with `APP_ENV=production`. Every figure
below comes from `tools/firefox-audit.py`, which is committed, and the JSON it
writes alongside each screenshot.

One thing had to be configured before any of it meant anything: **headless
Firefox reports no pointer capabilities at all**, so `(hover: hover)`,
`(pointer: fine)` and `(pointer: coarse)` are all false and every rule gated on
one of them silently never applies. A run that did not say which device it was
standing in for would have been measuring a machine no reader owns. The gate
now declares it — `--pointer fine` for a mouse, `--pointer coarse` for a
finger — and the two are measured separately throughout.

---

## Routes, widths and themes

`/`, `/projects`, `/projects/kushim`, `/about`, `/contact`, `/login` at 320,
768 and 1440 px in both light and dark: **36 screenshots, zero failures.** The
same six routes with JavaScript disabled: **36 screenshots, zero failures.**

Private surfaces, signed in, at the same three widths in both themes:

| Surface | Account | Screenshots | Result |
|---|---|---|---|
| `/admin`, `/admin/messages`, `/client` | admin | 18 | no failures |
| `/admin`, `/admin/messages`, `/client` | admin, reduced motion | 18 | no failures |
| `/admin`, `/admin/messages`, `/client` | admin, no JavaScript | 12 | no failures |
| `/client` | client | 6 | no failures |
| `/client` | client, no JavaScript | 4 | no failures |

Each screenshot is accompanied by an assertion, not just an image: horizontal
overflow must be zero, the document must have a title, every resource must be
local, `Facet Sans` must have loaded, and hostile inbox content must not have
become executable. All of those held on every combination.

**Role separation was observed in both directions.** The admin account receives
a 403 at `/client` — a short, correct page — and the client account receives
the real Client area. That asymmetry is the authorisation contract working, and
it is why the console gate's "did this page render anything" floor is a floor
and not a length budget.

---

## Fallback profiles

Each profile is a real browser configuration, not a stub: WebGL is disabled
through `webgl.disabled`, the low-tier signal through
`dom.maxHardwareConcurrency`, and reduced motion through Firefox's own
`ui.prefersReducedMotion`. Each page is re-parsed inside an instrumented
same-origin iframe whose console is owned *before* any of the page's modules
exist, so nothing logged during startup can be missed.

| Profile | Hero | Canvases | Ribbons live | Sections staged | Cards | Console noise |
|---|---|---|---|---|---|---|
| Normal | `live` | 1 | 5 / 5 | 4 | 3 | **0** |
| No WebGL | `static` | 0 | 5 / 5 | 4 | 3 | **0** |
| Low tier | `static` | 0 | 5 / 5 | 4 | 3 | **0** |
| Reduced motion | `static` | 0 | **0 / 5** | **0** | 3 | **0** |

Two things in that table are the point of it. A browser with no WebGL and a
low-tier device both keep the ribbons and the section entry — those cost no
GPU and declining them would be a degradation nobody asked for — while the
shader alone steps aside. Reduced motion switches off all three.

Zero console errors, warnings or unhandled rejections were recorded on any
route in any profile, public or private.

---

## Interaction

| Gate | Profile | Result |
|---|---|---|
| Card hit area | fine pointer | all 9 probe points reach the canonical link |
| Card hit area | coarse pointer | a tap at the card's corner navigates to `/projects/kushim` |
| Card focus parity | fine, coarse | focus lifts and lights identically to hover |
| Card | reduced motion | no travel, affordance kept, tracker not mounted |
| Ribbon loop | fine, 240 s | 474 samples, **0 seams**, running 474/474 |
| Ribbon speed | fine | 10 058–10 062 px in 240 s on every ribbon (41.9 px/s) |
| Ribbon yield | fine | pauses under a resting pointer, resumes from the same pixel |
| Ribbon yield | coarse | pauses under a held finger, resumes when it lifts |
| Ribbon keyboard | fine | 18 tab stops, **0** inside a ribbon or a copy |
| Ribbon semantics | all | all 28 canonical skills announced exactly once |
| Section entry | all routes | every staged section arrives; no layout moves |
| Scrolling | all routes | every `scrollBy` honoured exactly; `scroll-behavior: auto` |
| Hero lifecycle | fine | exactly 1 `pagehide` + 1 reduced-motion listener, 1 destroy |

---

## Runtime measurements

Frame health while scrolling a whole route, and while sweeping a pointer across
the catalogue grid. The budget is 34 ms at p95 — two frames at 60 Hz.

| Route | fps | frame p95 | frame max |
|---|---|---|---|
| `/` | 53.0 | **17.1 ms** | 82.5 ms |
| `/projects` | 60.0 | 17.1 ms | 17.2 ms |
| `/about` | 60.0 | 17.1 ms | 17.1 ms |
| `/contact` | 60.0 | 17.1 ms | 17.1 ms |
| `/login` | 60.0 | 17.1 ms | 17.1 ms |
| `/admin` | 60.0 | 17.1 ms | 17.1 ms |
| `/client` | 60.0 | 17.1 ms | 17.1 ms |

`/` is the expensive page and deserves its own sentence: it runs the WebGL
hero, five ribbons and the reveal observer at once, holds a one-frame p95, and
recorded a single 82.5 ms outlier during the scroll. That outlier is one frame
in a run of 67 and is not reproduced at p95; it is recorded here rather than
averaged away, and it is the one number on this page worth watching in Phase 5.

Catalogue grid under a pointer that never stops moving (960 synthetic moves
across 120 frames, dispatched at eight per frame against the real listeners):

| Profile | fps | frame p95 | main thread per move | per arrival on a new card |
|---|---|---|---|---|
| Fine pointer | 60.0 | 17.1 ms | **0.019 ms** | 0.098 ms |
| Coarse pointer (tracker not mounted) | 59.5 | 17.1 ms | 0.009 ms | 0.049 ms |

The gap between those two rows is the entire cost of the pointer-reactive
light: 0.010 ms of main-thread time per move, on a 16.7 ms budget.

### One measurement changed the implementation

The first version of the card light repositioned a gradient's origin inside a
card-sized box. The trace measured **43.8 fps and a 50.3 ms p95** on the
catalogue — a full repaint of the card on every frame the pointer moved. It was
rebuilt as a fixed-size disc that only ever changes `transform` and `opacity`,
with the offsets declared through `@property` as non-inherited lengths so a
write stops invalidating the whole card's subtree, and promoted to its own
layer only while the runtime is actually moving it. The same trace then
measured 60 fps and 17.1 ms — identical to the grid with no tracker at all.

Reading the card's rectangle inside the animation frame rather than in the
event handler is the second half of that: 0.098 ms per arrival against
**0.805 ms** when the read followed a style write.

---

## Bundle measurements and canonical Phase-5 budgets

The runtime and gzip figures in this decision were measured during the original
PORT-104 audit. They are retained as **historical evidence**; this corrective
does not present them as newly measured. The comparison is now against the
canonical Phase-5 thresholds rather than budgets invented from a repository-only
search.

Historical production build, `gzip -9`, against the last accepted figures in
`docs/decisions/PORT-99-signature-hero-renderer.md`:

| Artefact | PORT-99 | PORT-104 historical | Delta |
|---|---:|---:|---:|
| `app` CSS | 4 526 B | 4 888 B | +362 B |
| skin CSS | 4 061 B | 4 632 B | +571 B |
| `app` JS | 801 B | 801 B | 0 |
| skin JS (critical entry) | 1 213 B | 2 562 B | +1 349 B |
| `hero` chunk (deferred, home only) | 2 733 B | 2 731 B | −2 B |
| **First load, every route** (CSS + JS, no fonts) | 10 601 B | 12 883 B | +2 282 B |
| **Home, once idle** (first load + hero chunk) | 13 334 B | 15 614 B | +2 280 B |

Canonical comparison for the corrected candidate:

| Phase-5 contract | Candidate evidence | Result |
|---|---:|---|
| Critical JS gzip ≤ 50 KiB, excluding deferred visual chunk | 3 363 B / 3.28 KiB historical (`app` + skin entry) | within |
| Deferred visual chunk gzip ≤ 250 KiB | 2 731 B / 2.67 KiB historical hero chunk | within |
| Total WOFF2 ≤ 120 KiB / 122 880 B | 117 904 B / 115.14 KiB deterministic asset gate | within by 4 976 B |
| No major unused client dependency | no runtime client dependency; build-only packages remain dev dependencies | within |
| Non-LCP images dimensioned and lazy | no `<img>` is currently rendered; media placeholders reserve aspect ratio, and `ResponsiveImage` requires intrinsic dimensions for future assets | within (no candidate images) |

The +2 282 B historical first-load delta buys the interactive layer: the card
light and its tracker, five continuous ribbons, and section entry. It is
charged to every route because section entry applies everywhere and the card
grid appears on two routes. The shader remains deferred because it is the one
piece that only the home page can use.

The hero chunk is still fetched only where it is used, and the skin entry still
reaches it only through a dynamic import — re-asserted by
`tests/Smoke/SignatureHeroTest.php` against the current manifest. Font sizing,
cmap coverage, names, weights, metrics, OpenType layout features and checksums
are fail-closed in `tools/check-font-subset.py`.

---

## Security and content

No route, guard, session, CSRF, rate-limit, validation or query was touched by
this workstream. The suite that covers them ran green at every checkpoint —
845 tests, 17 877 assertions — including the authorisation matrix, error
disclosure, credential safety and prepared-statement gates. The browser sweep
independently re-confirmed that hostile inbox content does not become
executable at any width, in either theme, on every private surface.

Canonical content is unchanged: `content/` was not edited, and the corpus tests
assert every project, skill and experience still renders exactly once from the
same files.

---

## Reproducing

```
geckodriver --port 4444 &
php -S 127.0.0.1:8765 -t public &

python3 tools/firefox-audit.py --output <dir> --pointer fine \
    --routes / /projects /projects/kushim /about /contact /login \
    --widths 320 768 1440
python3 tools/firefox-audit.py --output <dir> --no-js --routes ... --widths ...
python3 tools/firefox-audit.py --output <dir> --card-interaction --pointer fine
python3 tools/firefox-audit.py --output <dir> --card-interaction --pointer coarse
python3 tools/firefox-audit.py --output <dir> --ribbons --ribbon-seconds 240 --pointer fine
python3 tools/firefox-audit.py --output <dir> --transitions --routes ... 
python3 tools/firefox-audit.py --output <dir> --console [--no-webgl|--low-tier|--reduced-motion]
python3 tools/firefox-audit.py --output <dir> --hero-lifecycle
```

Private surfaces need `--login-email` and `--login-password` for an account
created with `tools/create-admin.php`. No credential is recorded in this
repository.
