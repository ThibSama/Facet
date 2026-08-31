# PORT-135 — Satoshi Run: a Dino Run with a Bitcoin art direction

**Status:** implemented, awaiting human visual acceptance
**Date:** 2026-08-30
**Scope:** the presentation of the game PORT-134 built, and the way it is
found. Both renderers, the overlay's interface, the composition and the
trigger. `world.ts` is untouched: not one rule, constant or hitbox changed.
Nothing here reaches content, navigation, SEO, auth, contact, the database or
the deployment, and no package was added.

---

## The correction

PORT-134 shipped a game that worked and read as the wrong thing. It looked like
an abstract 3D blockchain runner — a long road running to a vanishing point,
watched from behind and to one side, populated by boxes whose meaning depended
on interpretation. What it was supposed to look like is a Dino Run.

The framing this checkpoint is built on:

> **Blockchain is the theme and the background. Dino Run is the game.**

The simulation was already that game. `world.ts` describes a runner at a fixed
point on a one-dimensional lane, with obstacles that come towards him and are
jumped or ducked — banks, red candles, central-bank barriers, FUD. Every one of
those was already there. The failure was entirely in how it was drawn.

So this is a presentation change, and its central decision is a camera.

## The camera stands still, and to one side

The old camera sat behind and above the runner and looked *down the lane*, with
its target well ahead of him. That is the composition of an endless-highway
runner, and it is what made the game read as one: depth carried the picture,
the obstacles arrived out of a vanishing point, and the player had to track a
receding road to work out what was coming.

The new camera looks straight across the lane, level with it, from a fixed
place. Three numbers are the whole composition:

| | |
|---|---|
| `RUNNER_COLUMN` | 0.22 — Satoshi holds a stable column a fifth of the way in |
| `GROUND_LINE` | 0.76 — the floor sits low, leaving the frame to the scenery |
| `FOV` | 30° — a long lens, because a long lens flattens perspective |

The camera is allowed two movements and no others: a fraction of the runner's
own rise, so a high jump is never lost off the top, and a short jolt on impact.
It never drifts, orbits, sways or chases. A side-on runner is read from one
fixed place, and a camera that moves turns a game of timing into a game of
tracking. Under reduced motion both collapse to zero.

**2.5D is now presentation rather than gameplay.** The play is on the plane
z = 0, exactly where the simulation always put it. The third dimension buys lit
and shaded faces, contact shadows, and — this is the part worth having — real
parallax for free: the scenery is not scrolled at a hand-tuned fraction, it is
simply *further away*, and a perspective camera moves a distant thing across
the frame more slowly than a near one by itself.

## How much lane is shown

Two constraints pull against each other. Fairness wants a long view: at the top
speed of 27 units/s the lane ahead has to be worth about three quarters of a
second. Readability wants a short one: a phone held upright cannot show that
much lane without shrinking the runner to a full stop.

The span is therefore interpolated on the stage's aspect — 24 units wide on a
wide stage, 13.5 on a narrow one — and the camera distance follows from it.
Both renderers use the same rule and the same numbers.

On a stage taller than it is wide the lane is **letterboxed** rather than
stretched, capped at a square, with the interface living in the bands above and
below. Filling a tall stage with world put the runner at a tenth of the frame
under half a screen of empty sky: the game made small so the shape of the
device could be honoured, which is the wrong way round. The stage stays
full-bleed underneath, so the whole screen is still a control and a thumb that
lands in a band still jumps.

## Fog is a fragment problem

The lane went pale and lost its floor, and the cause was two vertices nobody
can see. Distance haze was computed per vertex; the ground is one box two
hundred units long, so its corners sit a hundred units from a camera that is
five units from the part you are actually looking at, and a value interpolated
between those corners washes the whole visible span out to the background
colour. It is now computed per fragment from an interpolated world position.
The cost is one `vec3` varying and a `distance()` in the fragment stage; the
gain is that large surfaces have a colour at all.

## The cast

**Satoshi is a protagonist rather than a marker.** A hooded icon in about a
dozen solids: a round head — a disc, which is what makes him a person rather
than a post — a hood with a brim that fixes which way he is facing, a torso
with the ₿ struck on the chest, opposed arms and legs, and feet. The stride is
derived from the world's own distance rather than from wall-clock time, so two
runs of the same seed animate identically. Ducking changes his *shape* and not
merely his height: the body goes flat and forward, the head leads, the legs
trail — nobody can tell a duck from a short runner otherwise.

He is still not a likeness. Satoshi Nakamoto is a person nobody has seen, and
inventing a face would be inventing a fact.

**Coins are coins.** A Bitcoin that is not round is not a Bitcoin, and no
amount of orange on a box recovers the read — the silhouette is the whole of
the recognition. So the renderer grew a second primitive, a disc, and a coin is
now a struck one: a rim, a lit face, and the ₿ raised on it in six thick bars,
turning about its own axis so it has thickness. The bars are built rather than
typeset because a glyph disappears at twenty pixels. The mark's offsets are
rotated with the spin, or it would hang in the air beside the coin instead of
on it.

Bitcoin's orange is the coin's identity rather than the skin's, so it is the
one colour in the game that is not a semantic token.

**Obstacles are silhouettes first and references second.** A player who knows
nothing about banks or candles still has to see, in one glance and at speed,
whether a thing is on the floor or in the air. So everything standing on the
ground is bottom-heavy and solid, and everything hanging is a horizontal bar
with visible suspension:

| Kind | What it is | Verb |
|---|---|---|
| `bank` | plinth, colonnade, entablature, stepped pediment | jump |
| `candle` | a red bearish candle, body and wick, both inside the hitbox | jump |
| `barrier` | a suspended slab with hazard chevrons and two hangers | duck |
| `fud` | a hovering hazard sign with two warning marks and end lamps | duck |

The candle's wick is inside its hitbox rather than drawn above it, so the shape
the player judges is exactly the shape that can catch them.

**Contact shadows are information, not decoration.** In a jumping game a shadow
is how you read where you are about to land, so it costs one instance per
solid and it is the one piece of the 2.5D treatment the fallback keeps in full.

## The scenery stays scenery

Three layers, at three depths, all above the lane and none of them shaped like
anything the player has to act on: a far skyline of stacked blocks, the chain
itself — blocks in a line, linked, one in four lighting up as a confirmation —
and node masts with a lamp. Variety comes from hashing the index, so a tower's
height is the same every frame it is on screen without being stored anywhere,
which is also why the two renderers show the same city.

One rule was learned the hard way twice.

A draft put dark posts in *front* of the lane. At that distance perspective
magnifies them enormously, and what was meant as a hint of depth arrived as a
black slab sitting on top of the game. There is no foreground layer now;
foreground depth is bought on the face of the ground itself, where striations
travel at the fastest rate in the picture and cannot cover anything.

A draft also put the node masts at z = -11, which drew a lit vertical bar
running from the skyline down to the ground line — the exact shape, in the
exact place, of something the player has to jump. Scenery that can be mistaken
for an obstacle is worse than no scenery. They now sit behind the chain and
stop well clear of the lane.

## Dark and light are two designs

Light is not dark inverted, and the difference is not a hue.

**Dark** is nocturnal: the page's deep canvas pushed towards the accent, a lane
that glows against it, controlled luminous accents on the floor line, the
confirmations and the coins.

**Light** is mineral: a bright, faintly cool sky, and a genuinely grey ground
several steps down from it — a near-white lane under a near-white sky is a lane
whose edge nobody can find. The obstacles invert their relationship to the
background rather than their colour: they are *light on dark* in the dark theme
and *dark on light* in the light one, which is the Chrome Dino read and the
reason one geometry serves both themes.

Red stays red in both. It is the market's colour, not the skin's.

## The interface moved into the playfield

The old overlay was a lane with a panel of readouts, a status line and a bar of
four buttons stacked underneath it — an instrument that happened to have a
runner in it. The lane is now the whole overlay and everything else is laid
over its corners: score, coins and best top-left in a monospace readout with a
halo drawn from the page's own canvas, so it is dark behind light numbers and
light behind dark ones; Restart and Close as quiet pills top-right; the status
line at the foot; the two verbs in the lower corners.

The interface layer is a **sibling** of the stage rather than a child of it,
and that is load-bearing: the stage is itself a control, so a button nested
inside it would deliver every press twice. Overlaying instead of nesting keeps
them apart without a single event having to be stopped. The layer is
transparent to the pointer and each control turns the pointer back on for
itself.

Jump and Duck exist on every device and are never removed — they are how a
touch screen plays and they are also the pointer-only visitor's keyboard. What
changes is their weight: 4 rem targets in the corners of a phone, and a pair of
55%-opacity hints on a machine with a keyboard, still 48 px, still focusable,
still in the tab ring. Minimalism does not get to take an affordance away.

## The way in: five clicks on the mark

PORT-134's launcher was a visible button below the footer, and it said in its
own comments that it was provisional. It is gone — from the document, from the
stylesheet and from the runtime. Satoshi Run now has no button, no route and no
menu item: **five rapid clicks on the Facet mark in the top-left corner**, and
nothing else.

That makes the trigger a behaviour bolted onto a link with a job of its own,
and the whole risk lives in the seam between them. Three rules keep it honest.

- **A click that would go somewhere is never touched.** Only a click whose
  destination is the page already on screen is a candidate. On every other
  route the link is not merely untouched, it is not even counted: the first
  click leaves.
- **Only a pointer counts.** A click with no `pointerdown` behind it came from
  the keyboard. The obvious test — `detail === 0`, the click that came from no
  press — is not portable: Firefox reports 1 there, and a keyboard user tabbing
  to the mark and pressing Enter five times would have launched a game they
  never asked for. Enter navigates, reload included, the first time and every
  time.
- **Nothing waits on a timer a page load can win.** A draft held the reload for
  the length of the window and performed it if no second click came, so that
  even a lone click kept its navigation exactly. It worked, and it was wrong: a
  timer racing a navigation means a machine under load can reload the document
  out from under a gesture the visitor is halfway through. The full E2E matrix
  found it, which is the only reason it is not still there.

**The trade, stated rather than buried:** on the home page, clicking the mark
with a pointer no longer reloads the home page. That navigation is a request to
be shown the page you are already looking at — the only one in the document
that can be described that way — and the reload button, F5, Ctrl-R and Enter on
the mark all still perform it.

Sequences expire after 500 ms of silence, and the gap is checked against the
clock on every click rather than trusted to the timer, so a late timeout is
harmless. Five clicks mount exactly one game however many more arrive; the
handle is dropped when the run reports its own close, so Close or Escape leaves
the gesture usable again.

## What did not change

Deliberately, and asserted: the deterministic fixed-step simulation and every
number in it; the world/renderer/presentation separation; scoring; local best
score; keyboard and touch control; the renderer abstraction; WebGL2 with a
Canvas 2D fallback; mount-time refusal; runtime `webglcontextlost` degradation
with the run intact; one canvas at a time; reduced motion; theme adaptation
mid-run; `pagehide` teardown; Close/Escape lifecycle; open → close → reopen; the
single dynamic import; no run asset requested before the gesture; no framework;
and the bundle budgets.

No dependency was added. The capability that was missing — a round primitive —
cost a second vertex array and a second draw call, which is not a reason to
take on a scene library.

## Gates this adds

- `tests/E2E/satoshi-run.spec.ts` grows a *five-click gesture* suite: one to
  four clicks do not launch; five launch exactly one game; a pause resets the
  count; a fifteen-click burst dispatched inside a single synchronous frame
  mounts one game; Close and Escape each leave the gesture usable; the brand is
  still an anchor that navigates from another route; a pointer click on the
  home page does not reload while Enter still does; five keyboard activations
  are five navigations. The deferred-loading suite gains a second-launch case
  proving the module is fetched once per document, and its document assertion
  now proves no visible launcher is served on any route.
- Every other case in the file was rewritten onto the gesture, so the trigger is
  exercised by the whole suite rather than by its own tests alone.
