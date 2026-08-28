# PORT-110 — Effect tiers: what runs, for whom, and why

The `evolving-interface` skin ships four enhancements over a document that is
complete without any of them: the card light, the skill ribbons, section entry
and the signature hero. This record states which reader receives which, on what
evidence, and where each decision is enforced — so "the effect did not run" is
always a readable answer rather than a mystery.

Two rules bound the whole design and are worth stating before the matrix:

1. **Nothing here is informational.** Every effect decorates markup the server
   already sent. Declining one costs a reader motion, never content. The
   reduced-motion column of the matrix is a smaller page in *pixels animated*,
   not in facts.
2. **No user agent is ever sniffed.** Every branch below reads a capability —
   a media query, a device counter, a live geometry check, or the plain success
   or failure of an API call. There is no UA string anywhere in
   `resources/skins/` or `resources/js/`.

---

## The signals

| Signal | Read from | Why it is trusted |
|---|---|---|
| Reduced motion | `matchMedia('(prefers-reduced-motion: reduce)')` | The reader said so. It is the only signal that is a stated preference rather than an inference, so it outranks every other. |
| Fine pointer | `matchMedia('(hover: hover) and (pointer: fine)')` | The card light follows a *path*. A tap has one position and no path, so a coarse pointer is not a weaker version of this input — it is the wrong input. |
| Hardware concurrency | `navigator.hardwareConcurrency <= 4` | Not a GPU measurement, and not meant as one. The question is only "is there reason to think an animated effect is a bad idea here". |
| Device memory | `navigator.deviceMemory <= 4` | Chromium-only. Its **absence is never read as low tier** — an unknown must not become an answer. |
| Slot geometry | `slot.getClientRects().length === 0` | Below 40rem the hero visual is not rendered at all. Checked *before* the dynamic import, so the shader is never fetched for a box nobody has. |
| WebGL2 | `canvas.getContext('webgl2', { failIfMajorPerformanceCaveat: true })` returning null | The only honest capability test for a renderer is asking it to exist. A software-rasterised context is refused on purpose: the CSS fallback is the better product there. |

The two device counters are deliberately **conservative in one direction**. The
cost of wrongly calling a capable machine low-tier is a static hero that already
looks finished; the cost of the opposite is a shader competing for a phone's
main thread. Only one of those is worth being wrong about.

---

## The tier matrix

Measured on this SHA against Firefox 154.0.1 / geckodriver 0.37.1, one profile
per row, each configured with a real browser preference rather than a stub —
`ui.prefersReducedMotion`, `webgl.disabled`, `dom.maxHardwareConcurrency` and
`ui.primaryPointerCapabilities`. Reproduce with
`tools/firefox-audit.py --console [--reduced-motion | --no-webgl | --low-tier | --pointer fine | --pointer coarse]`.

| Profile | Hero | Canvases | Ribbons live | Sections staged | Card light | Console noise |
|---|---|---:|---:|---:|---|---:|
| normal | `live` | 1 | 5 | 4 | — (harness reports no pointer) | 0 |
| pointer-fine | `live` | 1 | 5 | 4 | **tracked** | 0 |
| pointer-coarse | `live` | 1 | 5 | 4 | **declines** | 0 |
| low-tier (2 cores) | `static` | 0 | 5 | 4 | declines | 0 |
| no-WebGL | `static` | 0 | 5 | 4 | declines | 0 |
| reduced-motion | `static` | 0 | **0** | **0** | declines | 0 |

Three things in that table are load-bearing:

**A pointer is not a tier.** `pointer-fine` and `pointer-coarse` differ in
exactly one cell. A coarse pointer says nothing about what a device can draw,
only that there is no path to track, so the hero still runs and it is the card
light alone that stands down. Collapsing the two families into one "degraded"
rule would have let a real regression — a shader skipped on every tablet, or a
light left tracking a finger — pass as expected degradation.

**Reduced motion is the only row that removes an effect wholesale.** The
ribbons are motion and nothing else, so under reduced motion the reader gets
the wrapping list of chips the server sent. The `text` figure for that profile
is 3 732 characters against 4 429 elsewhere, and the difference is *entirely*
the `aria-hidden` ribbon clones the runtime would otherwise have added: the
canonical list appears once either way. No information is lost — the animated
case simply carries visual duplicates that no screen reader, search engine or
no-JavaScript reader ever receives.

**Every degraded row is silent.** Zero console noise across all six profiles,
counted by a console owned *before* the page's own modules exist. A decorative
effect declining to run is not a fault and does not get to say so.

---

## Nothing expensive is on the critical path

The hero is the only expensive thing in the skin, and three separate mechanisms
keep it off the path to first paint. From the Chrome 152 waterfall on this SHA:

| Resource | Render-blocking | Fetch start |
|---|---|---:|
| `app` CSS | **blocking** | 22.5 ms |
| skin CSS | **blocking** | 23.6 ms |
| `app` JS (`type="module"`) | non-blocking | 23.6 ms |
| skin JS (`type="module"`) | non-blocking | 23.7 ms |
| both WOFF2 | non-blocking | 66.5 / 81.4 ms |
| **`hero` chunk** | non-blocking | **286.2 ms** |

`domContentLoaded` is 175.9 ms and the load event 190.6 ms, so the shader is not
even *requested* until roughly 96 ms after the page has finished loading. That
is the design working as written: the hero is reached through a dynamic import,
scheduled by `requestIdleCallback` with a 1 000 ms timeout, and gated on a
geometry check before the import. The LCP candidate is the server-rendered `h1`,
which is in the initial HTML and painted long before any of this.

The hero slot exists on the home page and nowhere else, and the skin entry
reaches the chunk only through a dynamic import — both re-asserted against the
real manifest by `tests/Smoke/SignatureHeroTest.php`.

---

## Continuous work stops when nobody is watching

| Effect | Loop | Stops when |
|---|---|---|
| Hero | `requestAnimationFrame` | An `IntersectionObserver` on the slot pauses it off screen. |
| Ribbons | CSS animation on a promoted layer | Held off screen, and while a pointer rests, presses, or focus is inside. |
| Section entry | none | An `IntersectionObserver` that unobserves each section once it has arrived. |
| Card light | one `rAF` per pointer move | Nothing is scheduled while the pointer is still. |

The hero's pause is now measured rather than asserted.
`tools/firefox-audit.py --hero-offscreen` wraps `requestAnimationFrame` in a
same-origin iframe *before* any module exists to call it, then counts frames on
screen, off screen, and after scrolling back:

```
{ "hero": "live", "canvases": 1,
  "onscreen": 24, "offscreen": 0, "resumed": 31,
  "slotBottomWhileScrolled": -1987 }
```

**Zero frames off screen**, and the loop resumes when the slot returns — the
pause is a pause, not a one-way exit.

Main-thread cost while everything is running, from the same harness on a quiet
machine (load average 4.9 on 12 cores):

| Trace | Measured | Budget |
|---|---:|---:|
| Card light, main-thread work per pointer move | 0.0146 ms | 0.02 ms |
| Card light, worst arrival on a card | 0.0488 ms | 0.5 ms |
| Frame p95 under a pointer storm (960 moves) | 17.1 ms | 34 ms |
| Frame p95 scrolling `/` end to end | 17.1 ms | 34 ms |
| Frame p95 scrolling `/projects` end to end | 17.1 ms | 34 ms |
| Ribbons, 60 s continuous watch | 119 samples, every strip `running`, 0 focusable clones | no drift |

> **A note on contention, kept deliberately.** The first run of these traces
> failed — frame p95 83.3 ms on `/` and 0.0208 ms per pointer move — because a
> headless Chrome leaked from an earlier measurement was holding roughly six
> cores at load average 28.7. Nothing in the repository changed between that run
> and the passing one above; only the leaked process was killed. The failing
> figures are recorded here because a timing gate that is only ever reported
> when it passes is not a gate, and because the far more useful lesson is that
> these numbers say as much about the machine as about the code. Every timing in
> this document carries the load average it was taken at.

---

## Failure leaves the document intact

`mountHero` returns null — silently, without touching the DOM — when WebGL2 is
unavailable, when the context is refused, when either shader fails to compile,
or when the program fails to link. The skin marks the slot `static` and the
server-rendered fallback stays exactly as it was. The `no-webgl` row above is
that path exercised for real, with Firefox's own `webgl.disabled`, and it
produces a complete page and an empty console.

Teardown is proven separately by `tools/firefox-audit.py --hero-lifecycle`: while
the effect is mounted the page holds **exactly one** `pagehide` listener and
**exactly one** reduced-motion listener regardless of how many effects mounted,
`destroy()` runs exactly once, repeated teardown signals are idempotent, and
turning reduced motion on mid-visit restores the accepted static fallback rather
than a half-torn-down page.

---

## What is not decided here

The hero's guards are reduced motion, slot geometry, the two device counters and
the renderer's own refusal. A coarse pointer is deliberately **not** among them,
for the reason given above. Should a future measurement show the shader costing
real battery on capable touch devices, that is a new decision with new evidence —
not a silent tightening of this one.
