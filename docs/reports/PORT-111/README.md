# PORT-111 — the publication Lighthouse gate, as measured

This directory holds the raw evidence behind the PORT-111 gate. The gate had
been *reported* before; nothing durable was committed with it, so the numbers
could not be re-read, re-derived or contradicted. Everything below comes from
the three JSON reports beside this file, and
`tests/Unit/Publication/LighthouseGateEvidenceTest.php` recomputes the medians
from those same files on every `composer test` — the prose here cannot drift
away from the reports without the suite failing.

Nothing about the product was changed to obtain these figures. No audit was
suppressed, no wait was inserted, no runtime behaviour was altered for the
benefit of the audit; the effect tiers, hero, ribbons, cards, reveals, SEO,
accessibility and security posture accepted in PORT-109 and PORT-110 are
exactly what was measured.

## What was measured

| | |
|---|---|
| Tested commit | `e2359351db191678e548d718fa02e0a17e94e63f` (`perf: meet publication performance budgets`) |
| Worktree | clean, `HEAD == origin/main` at measurement time |
| Frozen build digest | `eee0af588dc3aa12a4abcdb61ee5ac7d60415565c5a381099e331dde687da8f3` |
| Audited URL (transport) | `http://127.0.0.1:8000/` |
| `APP_URL` (canonical origin) | `https://facet.thibaultpaul.com` |
| `APP_ENV` | `production` |
| Runs | 3, over one build, no rebuild between them |
| Date | 2026-08-28, 22:42–22:43 CEST (20:42 UTC) |

### The build was frozen, not rebuilt

`rm -rf public/build && npm run build` produced the nine artefacts once. The
digest above is

```
find public/build -type f | LC_ALL=C sort | xargs sha256sum | sha256sum
```

taken **before** run 1 and again **after** run 3; both reads returned
`eee0af58…`, which is also the digest PORT-109 recorded for this SHA. The three
runs therefore audited one identical set of bytes.

### Transport and canonical origin are two different things

They are recorded separately on purpose. `APP_URL` is deployment configuration
that decides canonical SEO output and is never derived from a request header
(`src/Seo/SiteUrl.php`); it was set to the production origin
`https://facet.thibaultpaul.com`, and the served document accordingly emits
`<link rel="canonical" href="https://facet.thibaultpaul.com/">`. The audit
itself has to reach a socket on this machine, so Lighthouse drove
`http://127.0.0.1:8000/`, served by `php -S 127.0.0.1:8000 -t public
public/index.php`. Conflating the two would have meant either a localhost
canonical — which `SiteUrl` refuses outright in production — or a claim to have
audited a host that was never contacted.

## Tooling and environment

| | |
|---|---|
| Lighthouse | 13.4.1 (`lighthouse --version`) |
| Chrome | Google Chrome 152.0.7977.64, driven headless (`HeadlessChrome/152.0.0.0`) |
| Node | v24.18.0 |
| PHP | 8.5.4 (cli-server SAPI) |
| OS | Linux 7.0.0-30-generic x86_64 |
| CPU | AMD Ryzen 5 2600 (6C/12T), 30 GiB RAM |
| Lighthouse `benchmarkIndex` | 2232.5 / 2237.5 / 2119.5 (runs 1–3) |

The machine was otherwise idle; the three runs are consecutive, each about
6.6 s of Lighthouse wall time.

## Profile

The stock Lighthouse mobile profile, stated explicitly rather than assumed:

```
lighthouse http://127.0.0.1:8000/ \
  --only-categories=performance,accessibility,seo \
  --form-factor=mobile --screenEmulation.mobile --throttling-method=simulate \
  --output=json --output-path=docs/reports/PORT-111/lighthouse-run-N.json \
  --chrome-flags="--headless=new --no-sandbox --disable-gpu"
```

| Setting | Value |
|---|---|
| Form factor | `mobile` |
| Screen emulation | 412 × 823 CSS px, DPR 1.75, mobile |
| Throttling method | `simulate` (Lighthouse's simulated lantern throttling) |
| Network | 150 ms RTT, 1 638.4 Kbps throughput, 562.5 ms request latency, 1 474.56 Kbps down / 675 Kbps up |
| CPU | 4× slowdown |

Every one of these values is read back out of `configSettings` in all three
committed reports, and the test asserts the form factor and throttling method
so a future run with a different profile cannot be filed here silently.

## Raw results

| Run | Fetched (UTC) | Performance | Accessibility | SEO | LCP | CLS | TBT |
|---|---|---:|---:|---:|---:|---:|---:|
| 1 | 20:42:07 | 97 | 100 | 100 | 2 104.88 ms | 0.001648 | 0 ms |
| 2 | 20:42:19 | 97 | 100 | 100 | 2 105.31 ms | 0.000279 | 0 ms |
| 3 | 20:42:30 | 97 | 100 | 100 | 2 255.86 ms | 0.000279 | 0 ms |

FCP and Speed Index track LCP closely (2 104.88 / 2 105.31 / 2 105.86 ms) —
the page's largest contentful paint *is* its first, because no image is loaded
and the text paints once the subset fonts arrive.

## Medians against the gate

The median of three sorted values, computed from the JSON by
`LighthouseGateEvidenceTest`:

| Metric | Threshold | Median | Margin | Result |
|---|---|---:|---|---|
| Performance | ≥ 90 | **97** | +7 | pass |
| Accessibility | ≥ 95 | **100** | +5 | pass |
| SEO | ≥ 95 | **100** | +5 | pass |
| LCP | ≤ 2 500 ms | **2 105.31 ms** | 394.69 ms to spare | pass |
| CLS | ≤ 0.1 | **0.000279** | two orders of magnitude to spare | pass |
| TBT | ≤ 200 ms | **0 ms** | full budget unspent | pass |

All six clear. The gate passes on the first three-run set: no run was
discarded, repeated or re-ordered, and this is the only set that was taken.

## Outliers

There was no failure and no catastrophic run. Two small variations are worth
naming rather than smoothing over:

- **Run 3's LCP is 151 ms slower** (2 255.86 ms vs ≈2 105 ms). Its
  `benchmarkIndex` is also the lowest of the three (2 119.5 vs ≈2 235), i.e.
  the host was momentarily slower, and simulated throttling scales its estimate
  with the observed CPU. The spread is 7 % on a metric sitting 16 % under its
  ceiling, and the score did not move.
- **Run 1's CLS is 0.001648 vs 0.000279** for runs 2 and 3. Both are
  effectively zero — the layout reserves its own geometry, including for the
  `role="img"` placeholders that stand in for the site's absent images — and
  the larger of the two is still 60× under the threshold.

Neither changes any category score, and the median is unaffected by both:
run 3 is the maximum of the LCP set and run 1 the maximum of the CLS set, so
each falls outside the middle position by construction.

## Reproducing this

```bash
rm -rf public/build && npm run build
find public/build -type f | LC_ALL=C sort | xargs sha256sum | sha256sum   # expect eee0af58…
php -S 127.0.0.1:8000 -t public public/index.php &                        # with APP_ENV=production
for i in 1 2 3; do lighthouse http://127.0.0.1:8000/ ... ; done            # profile above
composer test                                                             # re-derives the medians
```

`.env` is not committed (it never is), so the audit environment is stated here
instead: `APP_ENV=production`, `APP_URL=https://facet.thibaultpaul.com`,
`APP_DEBUG=false`, `APP_LOCALE=en`, and a locally generated `APP_KEY`. The
public site is file-backed, so no database configuration participates.
