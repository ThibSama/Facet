# PORT-103 — Section transitions, and the decision not to use Pretext

**Status:** decided
**Date:** 2026-08-28
**Scope:** section-entry motion on every rendered route, and whether a
line-measurement library belongs in this project. Nothing here changes any
canonical content: sections, headings and prose are server-rendered and stay
that way.

---

## Decision 1 — section entry

**One `IntersectionObserver`, `opacity` and `transform` only, applied solely to
sections that are below the fold when the runtime starts.**

No scroll listener exists anywhere in the skin. The page scrolls at the
reader's own speed with their own inertia; `scroll-behavior` is left at `auto`
on both the root and the body, and the gate verifies that a programmatic
`scrollBy(0, 400)` moves the viewport by exactly 400 pixels on every route.

Sections already on screen are never staged. That is the difference between a
transition and a flash: a reader who has already been shown a heading must not
watch it disappear so that it can arrive.

### Why `transform` and not spacing

`opacity` and `transform` are the two properties a compositor can animate
without consulting layout. The gate measures the consequence rather than
trusting the claim: `offsetTop` and `offsetHeight` of every section, and the
document's `scrollHeight`, are recorded before any reveal and again after every
section has arrived.

| Route | Height before → after | Section offsets | Frame p95 while scrolling |
|---|---|---|---|
| `/` | 3612 → 3612 | unchanged | 33.2 ms |
| `/projects` | 2160 → 2160 | unchanged | 17.1 ms |
| `/about` | 4065 → 4065 | unchanged | 17.1 ms |
| `/contact` | 1507 → 1507 | unchanged | 17.1 ms |
| `/login` | unchanged | unchanged | 17.1 ms |

`/` is the expensive page and is worth naming: it carries the WebGL hero, five
running skill ribbons and the reveal observer at once, and still scrolls inside
a 34 ms budget. Every other route sits at one frame.

### Reduced motion

The runtime declines to mount the observer at all, so no section is ever
staged — the gate confirms zero staged sections on every route under Firefox's
own `ui.prefersReducedMotion`. A `@media (prefers-reduced-motion: reduce)`
block additionally neutralises the entry state, which covers the moment between
the document being styled and the runtime running, and a preference that
changes mid-visit tears the observer down and removes every attribute it set.

### No JavaScript

Not one of the rules involved exists without `[data-facet-reveal-section]`, and
only the runtime ever writes it. The served document has no entry state, and
`@media print` neutralises it too — paper has no viewport, so nothing on it can
be waiting to arrive.

---

## Decision 2 — Pretext

**Not used. Not downloaded, not installed, not vendored.**

The brief allows it only if a real multi-line measurement problem and a
measured benefit are both proven. A survey was run first, and it proves the
opposite.

### What was measured

Every `h1`, `h2`, `h3` and `p` inside `main`, on `/`, `/projects`, `/about` and
`/contact`, at 320, 768 and 1440 px, in real Firefox 154.0.1. Line boxes were
taken from a `Range`'s client rects — the browser's own breaking, not an
estimate — and grouped into visual lines. 81 blocks broke across more than one
line.

| Measure | Result |
|---|---|
| Multi-line blocks surveyed | 81 |
| Multi-line `h1` / `h2` | **0** |
| Multi-line `h3` | 3, none with a short last line |
| Blocks with a last line under 25 % of the longest | 17, **all of them body paragraphs** |
| `CSS.supports('text-wrap', 'balance')` | **true** |
| `CSS.supports('text-wrap', 'pretty')` | false |

### What that means

The headings — the only place where an unbalanced break is a visual defect
rather than ordinary typography — are already balanced, natively, by a
`text-wrap: balance` declaration this skin has carried since PORT-53. The
computed value on a rendered `h2` is `balance`, so the rule is in effect and
not merely present. Zero heading orphans were found at any width, which is the
measurement that a balancing library would have to improve on.

The 17 short last lines are all body paragraphs, and a short last line in a
paragraph of prose is not a defect. The CSS feature that addresses those is
`text-wrap: pretty`, which Firefox 154 does not support; when it ships it will
be a one-line change with no dependency, no bytes and no runtime.

So there is no problem to measure a benefit against. Adding a library here
would mean shipping JavaScript that re-measures, at runtime and on every
resize, breaks the browser has already made correctly — the precise shape of
cost this project has rejected elsewhere.

### Rejected alternatives

**Adding `text-wrap: pretty` speculatively.** Free and forward-compatible, but
it changes nothing measurable in any browser this project has tested, and a
declaration whose effect cannot be observed cannot be reviewed. It is the
obvious first move the day the measurement above changes.

**Manual `&nbsp;` or `<wbr>` in templates.** Would put presentation decisions
into content, at every width at once, and only the ones an author happened to
foresee. Rejected on principle: the corpus states facts, not line breaks.

---

## Reproducing these numbers

The transition figures come from `tools/firefox-audit.py --transitions`, which
is committed. The typography survey is scaffolding and is not: it drives the
same WebDriver session, collects `Range.getClientRects()` for every heading and
paragraph, groups rects into lines by their top edge, and reports the last
line's width as a fraction of the longest. The artefact worth keeping is this
record.
