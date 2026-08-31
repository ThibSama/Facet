# PORT-134 — Satoshi Run: architecture, renderer and cost

**Status:** decided
**Date:** 2026-08-30
**Scope:** a self-contained playable runner under
`resources/skins/evolving-interface/satoshi-run/`, a provisional launcher in
the skin's document shell, and the browser coverage that gates both. Nothing
here touches content, navigation, SEO, auth, contact, the database or the
deployment, and no package was added.

---

## Decision

**A pure simulation, a replaceable renderer boundary, and a chunk nobody
downloads until they press Play.**

Three separate commitments, each of which is asserted somewhere:

1. **The rules are pure.** `world.ts` has no DOM, no timers and no randomness
   of its own. A world is a seed plus a fixed-step function, so physics,
   spawning, collision, collection and scoring are all assertable without a
   frame being drawn. The browser suite does exactly that.
2. **The picture is behind an interface.** `renderer.ts` declares
   `RunRenderer`; `webgl-renderer.ts` and `canvas-renderer.ts` implement it.
   Nothing above that line knows which one mounted, which is what makes a
   different implementation — a scene library, if PORT-131 ever measures one to
   be worth its transfer cost — a drop-in rather than a rewrite.
3. **The cost is opt-in.** The game is reached through exactly one dynamic
   import, inside a click handler. A visitor who never launches it fetches no
   chunk, allocates no context and pays no frame.

## Why no scene library

Three.js was not installed and was not going to be: the dependency rule for
this ticket forbade adding one, and the budget it would have to fit in is the
250 KiB gzip deferred allowance the hero already shares. A minified Three.js
core is most of that allowance before a single line of game code, for a scene
that is a lane, some boxes and a camera that never leaves its rail.

What the game actually needs from a renderer is one geometry drawn several
hundred times with different transforms. That is `drawArraysInstanced` and a
forty-line shader, and it is what `webgl-renderer.ts` is: one unit cube, one
instance buffer rewritten per frame, one draw call for the whole scene.

The measured result is the argument. The game's chunk is **22.09 KiB raw /
8.89 KiB gzip**, plus **3.22 KiB / 0.88 KiB** of stylesheet — under 4% of the
250 KiB deferred budget, for a renderer with real perspective, per-instance
lighting and depth fog. Critical JS is unchanged at **3.53 KiB gzip** against a
50 KiB budget, because none of this is on that path.

## The lane, and why it is the historic one

The concept is preserved deliberately rather than reinterpreted. Satoshi
auto-runs; the player jumps and ducks and does nothing else; the obstacles are
the ones that ever threatened Bitcoin — bank towers, red candles, hanging
central-bank barriers and drifting banks of FUD — and the collectible is BTC.
Score is distance plus collection, so running far and collecting well are two
different ways to be good at it. Coin arcs sit at jump height on purpose: a
whole arc costs a commitment the lane may punish, which is the tension that
makes two scoring terms worth having instead of one.

The runner is stacked geometry wearing the ₿ mark. Satoshi Nakamoto is a person
nobody has seen, and inventing a likeness would be inventing a fact.

## Difficulty is expressed in seconds, not units

Obstacle spacing is drawn as **reaction time** — 0.85 s to 1.85 s — and
converted to distance at the current speed. It is the only way a run can keep
accelerating from 11.5 to 27 units/s without eventually becoming unfair: the
same 0.85 s is 10 units of lane at the start and 23 at the cap.

The jump has the same shape of reasoning. Holding buys height and airtime;
releasing caps the rising speed at 15 units/s rather than scaling it, so the
shortest possible tap still peaks at ≈1.81 and clears the tallest ground
obstacle at 1.62. A player who taps is playing a tighter game, not a broken
one. Sub-frame taps are latched in `controls.ts` for the same reason: the loop
samples intent once a frame, and a press shorter than sixteen milliseconds is
still a press.

## A renderer can die after it was chosen

The choice between the two lanes is made once, at mount, from what the browser
said it could do — and a browser is allowed to change its mind afterwards. A
driver reset, a compositor reclaiming GPU memory, a laptop switching cards: any
of them fires `webglcontextlost`, after which every GL call is a silent no-op.
The surface keeps its last frame while the simulation runs on behind it, which
the player experiences as a hang rather than as a fault.

Handling it is split along the boundary that already exists. The accelerated
renderer *reports* the loss and stops — one listener, removed in `destroy()`,
and it deliberately does not call `preventDefault()`, because preventing the
default action is how a renderer asks for a context back later and this one
does not want one. What to do about it is a lifecycle decision, so it lives in
`run.ts`: mount the 2D lane on a **fresh** canvas, swap the element, and carry
on. The element has to be new because a surface that has served a WebGL2
context will never serve a 2D one — which is also why this cannot live inside a
renderer, since no renderer owns the element it draws on.

`world.ts` is not touched by any of it. The score, the distance, the coins, the
obstacles and the player's held keys are all exactly where they were: losing a
picture of a game is not losing the game. The overlay records
`data-facet-run-degraded="context-lost"` and its renderer attribute flips to
`canvas2d`, which is the only trace the fallback leaves and the one the suite
holds on to.

## Closing is something the run reports

`active` in `skin.ts` is what refuses a second game, so it has to mean "a game
is on screen" rather than "a game was once started". A run reached its own exit
— the Close button, Escape — without saying so, which left that handle pointing
at a run that no longer existed and the launcher dead until a reload: a game
with a Quit button rather than a Close button.

So closing is not something done *to* the run from outside. `destroy()` reports
it, once and last, after the document is already whole again — telling the
owner earlier would invite it to mount a second run into a document the first
has not finished vacating. `destroy()` stays idempotent, so `pagehide` after a
local close is a no-op rather than a second teardown.

## Reduced motion

The lane still scrolls and the runner still jumps, because that is the game
rather than decoration around it. What is removed is everything that is not:
the camera stops drifting and lifting, the impact stops shaking, the sparks are
never created, the coins stop spinning and the overlay's arrival animation is
not played. The renderers own that decision, because that is where the movement
actually is.

## What is provisional

The launcher, and only the launcher. It is one typed button below the footer,
rendered on the home route, shipped `hidden` and revealed by the skin runtime —
the plainest thing that can start a game. The discovery design is PORT-131's
business; replacing the trigger changes nothing behind it, because everything
that knows about the trigger is one function in `skin.ts`.

## Gates this adds

- `tests/E2E/satoshi-run.spec.ts` — twenty-six cases across three engines:
  determinism, physics, ducking clearance, scoring, a survivable lane, the
  complete loop, keyboard and touch, persistence, both renderers, both themes,
  a phone viewport, reduced motion, and teardown on Escape and on `pagehide`.
  Two suites gate the lifecycle specifically: *closing and relaunching* opens
  and closes the game repeatedly and asserts that nothing accumulates and that
  the launcher still works, and *losing the context* kills a live WebGL2
  context with `WEBGL_lose_context` and asserts the run continues on the 2D
  lane with its score intact.
- `tests/Unit/Asset/BundleBudgetTest.php` — the skin's deferred imports are now
  asserted as an exact set, and every deferred chunk is charged for its
  stylesheet as well as its JavaScript.
