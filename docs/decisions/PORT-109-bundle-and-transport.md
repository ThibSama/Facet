# PORT-109 — Bundles, dependencies, media and the transport boundary

Measured on the production build of `886c3ac`, re-measured rather than carried
forward from PORT-104. Every figure below was taken on this SHA.

## The build is reproducible

`rm -rf public/build && npm run build` produces the same nine artefacts with the
same content hashes and the same SHA-256 digest across repeated runs
(`eee0af58…`). The inventory that follows therefore describes a build anyone can
reproduce, not one run's output.

## Inventory

`gzip -9`, and `raw` for the fonts, which are already compressed.

| Artefact | Raw | gzip -9 | Loaded |
|---|---:|---:|---|
| `app-B6PRi25X.js` | 1 883 B | 801 B | every route |
| `skin-evolving-interface-DpE9Q_5q.js` | 6 409 B | 2 562 B | every route |
| `hero-B-Yppyz4.js` | 5 801 B | 2 731 B | home only, after idle |
| `app-VLqotGBf.css` | 19 710 B | 4 888 B | every route |
| `skin-evolving-interface-C3ugi1K1.css` | 25 729 B | 4 632 B | every route |
| `facet-lato-regular-DdSZPBK2.woff2` | 59 348 B | — | on first text paint |
| `facet-lato-bold-B_YWN1Yr.woff2` | 58 556 B | — | on first text paint |
| `skin-fixture-unselected-*` | 63 B | — | **never** — built to prove isolation |
| `manifest.json` | 1 515 B | 407 B | server-side only |

| Budget | Measured | Result |
|---|---:|---|
| Critical JS gzip ≤ 50 KiB (51 200 B), excluding the deferred visual chunk | **3 363 B** (3.28 KiB) | within, by 47.7 KiB |
| Deferred visual chunk gzip ≤ 250 KiB (256 000 B) | **2 731 B** (2.67 KiB) | within, by 247.3 KiB |
| Total WOFF2 ≤ 120 KiB (122 880 B) | **117 904 B** (115.14 KiB) | within, by 4 976 B |

These were narrative in PORT-104 and are now executable:
`tests/Unit/Asset/BundleBudgetTest.php` reads the real manifest and the real
emitted files and fails the suite if any of the three is exceeded, if the hero
stops being a dynamic entry, or if either font reaches the build altered.

## Dependencies

`package.json` declares **no runtime dependencies at all** — `dependencies` is
empty, and all seven packages are `devDependencies` (Vite, Tailwind, TypeScript,
ESLint and their plugins), none of which ship. No emitted chunk contains a
`node_modules` path, and the whole import graph is eight first-party edges:

```
app.ts        → ../css/app.css, ./nav, ./theme
skin.ts       → ./skin.css, ./cards, ./ribbons, ./reveal
skin.ts ⇢ ./hero          (dynamic — the only dynamic edge)
```

There is no dead weight to remove: there is nothing here that is not
first-party, and the one heavy thing — the shader — is already deferred.

## Media

**No `<img>` element is rendered anywhere on the site.** This is a deliberate,
pre-existing decision recorded in `views/partials/media.php`: a `Media` entry may
have no source, so the skin emits a `<div role="img">` carrying the entry's
mandatory textual description and reserving its geometry inline via
`aspect-ratio`. That reservation is why a card grid does not reflow.

It is proven, not asserted — `AboutPageTest`, `ProjectCatalogueTest`,
`ProjectCaseStudyTest` and `HomeCompositionTest` each assert zero `//main//img`
against rendered markup — and the Chrome waterfall independently reports
`imgCount: 0` on the live home page. Nothing was invented to fill the gap:
`ResponsiveImage` already requires intrinsic dimensions, so the first real asset
will be dimensioned by construction.

## Cache classification

Every path this build emits, run through the real classifier:

| Path | Classification |
|---|---|
| all nine `/build/assets/*` files | `public, max-age=31536000, immutable` |
| `/build/manifest.json` | `no-cache` |
| `/`, `/projects` | `no-cache` |

Zero emitted assets fall outside the immutable class, which is the property that
matters: an unfingerprinted file would be cached for a year under a name a
deploy reuses. `BundleBudgetTest` now asserts this against the manifest, so a
future build that emits an unfingerprinted artefact fails rather than ships.

## The waterfall, and where this repository's authority ends

Chrome 152.0.7977.64, 1440×900, cache disabled, against a production-mode PHP
server. Eight subresources; render-blocking status read from the browser's own
`PerformanceResourceTiming`.

| Resource | Render-blocking | Fetch start |
|---|---|---:|
| `app` CSS | **blocking** | 10.7 ms |
| skin CSS | **blocking** | 11.0 ms |
| `app` JS (`type="module"`) | non-blocking | 11.2 ms |
| skin JS (`type="module"`) | non-blocking | 11.2 ms |
| `facet-lato-regular` | non-blocking | 33.7 ms |
| `facet-lato-bold` | non-blocking | 42.5 ms |
| `/favicon.ico` | non-blocking | 117.5 ms |
| `hero` chunk | non-blocking | **126.3 ms** |

`domContentLoaded` 98.2 ms, load 99.5 ms, FCP 128.0 ms. Exactly two
render-blocking resources, both stylesheets in `<head>`; both module scripts are
deferred by specification; the fonts are discovered from CSS and carry
`font-display: swap`; and the shader is not requested until ~27 ms *after* the
load event. The fixture skin's assets are never requested.

**Compression is not measured here, because it cannot be.** Every resource above
reports `encodedBodySize == decodedBodySize` — the PHP built-in server does not
compress, and PHP never proxies a Vite artefact in any environment. The gzip
column in the inventory is what the bytes *will* compress to; what a visitor
actually receives over the wire, and the `Cache-Control` header that reaches
them, is applied by the production web server. That is deliberately **Phase 6**,
and the split is honest: this repository owns the classifier and the artefact,
the deployment owns the transport.

## One thing left open

`/favicon.ico` answers **404 with a 5 229-byte HTML error page**. Every browser
requests it, so every first visit pays for a 5 KB document that says nothing.
It costs nothing on the critical path — it is fetched after load and blocks
nothing — and Lighthouse does not flag it, so it is recorded here rather than
fixed: choosing a site icon is a branding decision with an asset attached, and
PORT-58 is not the place to invent one.
