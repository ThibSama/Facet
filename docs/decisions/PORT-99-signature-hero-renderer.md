# PORT-99 — Signature hero renderer: prototype, measurements and decision

**Status:** decided
**Date:** 2026-08-28
**Scope:** the decorative `[data-facet-hero-visual]` slot in the
`evolving-interface` hero. Nothing here affects hero content: the heading,
prose and calls to action are server-rendered HTML and stay that way.

---

## Decision

**Raw WebGL2, written by hand, loaded as a dynamically imported chunk, layered
over the accepted PORT-53 static CSS visual — which remains the base case.**

The static CSS visual is not a degraded mode. It is what the server sends, what
every reader sees first, and what stays on screen unchanged whenever the
accelerated path is unavailable, refused or switched off. WebGL2 is only ever
added on top of it.

---

## How the candidates were measured

Real Firefox 154.0.1 driven over WebDriver (`geckodriver` 0.37.1), **headed**,
1440×900, dark theme, on the hero slot at its true desktop size
(352×440 CSS px → 156 468 device pixels at DPR 1).

Host: AMD Ryzen 5 2600 (12 threads), Radeon RX 7800 XT, Mesa 26.0.3, Wayland.
WebGL reported a hardware renderer (`AMD / Radeon R9 200 Series, or similar`),
so these are GPU numbers, not software-rasteriser numbers.

Each run samples real `requestAnimationFrame` deltas for 4–5 s, discarding the
first five frames. `script/frame` is measured inside the candidate's own draw
call — it is the **main-thread** time the effect takes, the budget shared with
input handling and hydration.

### Reproducible low-tier simulation

`load=low` starves the machine two ways at once, both fixed and scripted:

1. `navigator.hardwareConcurrency - 1` Web Workers spinning a busy loop, so no
   core is idle;
2. a main-thread burner consuming **9 ms of every 16 ms tick**, which removes a
   fixed fraction of every frame budget.

This is a stand-in for a low-tier device that needs no low-tier device, and it
is the same load for every candidate.

---

## Measurements

### Normal desktop

| Candidate | FPS | frame p95 | frame max | script/frame | init | chunk (gzip) |
|---|---|---|---|---|---|---|
| A — static CSS (accepted PORT-53) | 60.0 | 18 ms | 19 ms | 0 ms | 10 ms | **0 B** |
| B — CSS keyframes | 60.0 | 18 ms | 18 ms | 0 ms | 16 ms | **0 B** |
| C — Canvas 2D | 60.0 | 19 ms | 21 ms | **2.358 ms** | 41 ms | 861 B |
| D — WebGL2 | 60.0 | 18 ms | 18 ms | **0.020 ms** | 35 ms | 2 090 B |

On an idle desktop every candidate holds 60 fps. This measurement does not
discriminate, and it was not expected to.

### Low-tier simulation — median of 4 alternating repetitions

| Candidate | FPS | frame p95 | frame max | script/frame |
|---|---|---|---|---|
| A — static CSS | 58.3 | 25 ms | 59 ms | 0 ms |
| B — CSS keyframes | 58.2 | 25 ms | 49 ms | 0 ms |
| C — Canvas 2D | 52.9 | 39 ms | **89 ms** | **3.426 ms** |
| D — WebGL2 | 53.6 | 36 ms | **53.5 ms** | **0.008 ms** |

Average FPS does **not** separate Canvas 2D from WebGL2 — 52.9 vs 53.6 is
inside run-to-run noise, and it would be dishonest to claim otherwise. Two
things do separate them, and both were stable across every repetition:

* **Main-thread cost.** WebGL2 spends 0.008 ms per frame against Canvas 2D's
  3.426 ms — a factor of ~430. Canvas 2D takes roughly a fifth of a 16.7 ms
  frame budget out of the same thread that services input, and it takes it on
  exactly the devices that can least afford it.
* **Worst-frame latency.** Canvas 2D's slowest frame was 100/61/118/78 ms
  across the four repetitions; WebGL2's was 51/70/56/51 ms. The long tail is
  what a visitor actually perceives as jank.

### Cost of the decision

WebGL2 costs **1 229 B gzip more** than Canvas 2D (2 090 B vs 861 B). That
premium buys back ~3.4 ms of main-thread time on every animated frame on a
contended device. At this size the trade is not close.

---

## Rejected alternatives

**Canvas 2D (candidate C).** Fully working and half the bytes, rejected on
main-thread cost and tail latency above. It would have been the choice had
WebGL2 been unavailable or materially larger.

**CSS keyframes (candidate B).** Genuinely excellent — 0 bytes, 60 fps, zero
main-thread cost, and it degrades by simply not running. Rejected as the
*signature* effect only because it cannot express refraction through a cell
field; a conic sweep over the existing shards is decoration, not a signature.
Its measurements are why the static fallback needs no JavaScript to feel
finished, and it remains the obvious upgrade path for the fallback if one is
ever wanted.

**WebGPU.** Unavailable: `navigator.gpu` is `undefined` in Firefox 154.0.1 on
this platform, so no adapter can be requested. Per the brief WebGPU is never
required, and nothing in the chosen design depends on it.

**Three.js / any WebGL helper library.** Not installed, and installing it is
out of bounds for this task — no downloads or package installation. It is also
~150 kB gzip against a 2 kB hand-written shader for a single full-slot quad:
two orders of magnitude of dependency for work that is one `drawArrays` call.
Rejected on both counts; the constraint and the merits agree.

---

## Why the accepted static visual stays the fallback

The brief asks for a premium static/no-JS fallback reviewed in both themes.
The PORT-53 visual already *is* that: layered gradients over the raised
surface, two bordered shard pseudo-elements, theme-aware shadow and restrained
glow. It was reviewed again in both themes during this prototype and is kept
**byte-for-byte as accepted** — the realignment moves hero *layout* to Tailwind
utilities but does not touch the visual's material.

Keeping it unchanged is what makes requirement 13 cheap to honour: the
accelerated layer is an absolutely-positioned canvas inside a slot that is
already sized by the fallback, so a canvas that never arrives changes no
geometry and shifts nothing.

---

## What this implies for PORT-100

* The canvas is created by JavaScript, never server-rendered — the home page's
  no-scripted-control contract stays true.
* The module is dynamically imported, so pages without a hero slot never fetch
  it.
* `prefers-reduced-motion`, a coarse low-tier signal, a refused WebGL2 context
  and any thrown error all resolve the same way: the fallback stays, untouched.
* `failIfMajorPerformanceCaveat: true` is deliberate. A software-rasterised
  WebGL context is exactly the case where the CSS fallback is the better
  product, so the context request is allowed to fail.

---

## What shipped, measured on the real page

The numbers above are the prototype's. These are the implementation's, taken on
the served home page in production configuration, median of three runs each:

| Profile | FPS | frame p95 | frame max | hero state |
|---|---|---|---|---|
| Normal desktop | 60.0 | 18 ms | 18 ms | `live` |
| Low-tier simulation | 58.2 | 27 ms | 63 ms | `live` |

58.2 fps under the same contention that the *static* control measured 58.3 fps
under: the effect is, within noise, free.

Production cost, `npm run build`, gzip:

| Artefact | Before | After | Delta |
|---|---|---|---|
| `app` CSS | 4 971 B | 4 526 B | **−445 B** |
| `app` JS | 801 B | 801 B | 0 |
| skin CSS | 4 145 B | 4 061 B | −84 B |
| skin JS (entry) | 196 B | 1 213 B | +1 017 B |
| `hero` chunk (deferred) | — | 2 733 B | +2 733 B, home only |

The shared stylesheet got *smaller* despite gaining the hero's utilities:
scoping Tailwind's source detection to `resources/` stopped repository prose and
test assertions from contributing rules to the stylesheet every visitor
downloads.

The hero chunk is fetched only where it is used. It is not requested on
`/projects`, `/about` or `/contact` — those pages have no hero slot — and not at
320 px either, where the slot is not rendered and the guard runs before the
import.

---

## Reproducing these numbers

The prototype harness is not committed: it is scaffolding, it depends on a
running `geckodriver`, and the artefact worth keeping is this record. To
rebuild it, serve a page containing the hero slot, mount each candidate,
sample `requestAnimationFrame` deltas with the load profile described above,
and measure module size with
`esbuild --bundle --format=esm --minify --target=es2022` followed by `gzip -9`.
