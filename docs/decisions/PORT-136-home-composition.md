# PORT-136 — the home page as one composition

**Status:** implemented, awaiting human visual acceptance
**Date:** 2026-08-30
**Scope:** the public home page `/`, its composition, and the home-scoped half
of the evolving-interface stylesheet. One renderer change, in `hero.ts`, is
included and argued for below. Nothing here reaches content, navigation, SEO,
auth, contact, the database, the deployment or Satoshi Run, and no package was
added.

---

## What was wrong

The old home page was not ugly. It was *arranged*: a header, a narrow hero, a
grid of near-identical cards, a row of small badges, a long timeline, a small
call to action, a footer — every section the same width, separated by the same
top margin and the same hairline. Every individual part was defensible and the
whole read as a template, because the composition never made a decision. A
visitor could not tell the three projects apart without reading them, the
journey took more vertical space than the work did, and the page ended on a
paragraph.

Four things were the actual cause, and each is answered below rather than
decorated over.

1. **One width for everything.** The page lived in a 76rem shell on a 1512px
   screen and nothing was ever allowed to be wider or narrower than anything
   else.
2. **One rhythm for everything.** `margin-top; padding-top; border-top` on
   every section is a list, not a rhythm.
3. **One shape for every project.** Three equal rectangles say the three
   projects are interchangeable.
4. **No ending.** The last section was the smallest thing on the page.

## The strategy

**Width is a per-section decision.** On the home route and nowhere else,
`.facet-main` gives up `.facet-shell` and becomes the width of the viewport.
Each section then states its own width: hero, work and journey take a measured
84rem column; skills and the finale are **bands** that run to the screen's own
edges. Nothing is sized in viewport units, so there is no scrollbar arithmetic
to get wrong — the guard is `overflow-x: clip` on the route's main, and the
measured no-overflow result at seven viewports from 320px up is in the report.

**Rhythm is per-section too.** The shared `margin/padding/hairline` rule is
neutralised on this route. Bands pay for their extra width with their own
surface; measured sections pay with space.

**One repeated ornament.** The rotated rounded shard the brand already carries
is the page's only decorative vocabulary: the eyebrow mark, the focus-area
marks, the journey's highlight bullets, the project figures and the finale's
one outsized cropped shard are all the same shape. Four sections that were each
decorated separately would have been the old problem in a new palette.

**Two and a half signature moments**, and everything else supporting them: the
hero's facet field, the work panels, and the skills strip running off the edge
of the screen. The finale is the ending rather than a fourth moment.

## The sections

**Hero.** Same architecture, different proportion. The H1 is unchanged in kind
and much larger in effect; the headline is promoted to a second display size;
the focus areas stop being pills and become an index line under a rule. The
signature visual is roughly half again as wide, is justified to the
composition's own edge, and — the part that actually matters — stops being a
card: the drop shadow is gone, the border is an accent-tinted rim, and a radial
mask feathers its far corner so it dissolves into an atmosphere painted behind
the whole first screen. Its progressive-enhancement architecture is untouched:
the slot is still empty, decorative, `aria-hidden`, sized entirely by the
document, and still carries no `data-facet-hero` the server guessed.

**Work.** Three panels that are not three of the same thing. The first is full
width and lays its figure beside its words from 64rem; the other two are half
width and stack. Each carries an abstract figure the stylesheet draws — a fan
of shards over a raked field, concentric orbits, strata cut by one long shard —
assigned by position, so a project added to the selection is always dressed
rather than left bare. **They illustrate nothing.** The corpus documents no
screenshot for any of these projects, and the honest treatment of missing
evidence is a mark, never a picture of an interface that would have to be
invented.

The card contract underneath is unchanged and deliberately so: one `<li>` that
is the positioning context, one `<article>`, exactly one anchor, that anchor's
own stretched overlay as the hit area, and no second focusable element. The
panel is a bigger card, not a different mechanism — which is why
`InteractiveProjectCardsTest` still passes without being touched.

**Skills.** The mechanism is the accepted one and was not reopened: the server
sends one wrapping list per canonical category, the runtime measures it,
repeats it enough times to cover the viewport, and CSS translates the strip.
What changed is that the strip now starts at the page's own left measure and
ends at the screen, the chips have real material, and the centre of each row
carries a static light. A row is covered whether the category holds two names
or eleven — measured, in the report.

> Superseded in part by the corrective pass below (F1): the centre light is no
> longer drawn at rest. It is now an interaction affordance.


**Journey.** The rail is gone and the entries are milestones on a grid: two
abreast from 58rem, with the period promoted to the first thing you see so the
chronology still reads across a grid. **No fact was removed.** Every date,
kind, title, organisation, location, summary and highlight is still rendered,
in the corpus's own order, in one ordered list. The section went from the
tallest thing on the page to roughly a fifth of it by composition alone.

**Finale.** A plate with the statement on the measure and the action at the far
side.

> Superseded in part by the corrective pass below (F2). As accepted, the plate
> was inked in **both** themes and reassigned the skin's semantic colours to
> their dark values inside itself. Human review found that a dark section in a
> light document reads as dark mode leaking into the page, and the plate now
> has a treatment per theme. The composition described here is unchanged, and
> the dark plate is unchanged to the pixel.

## Light is not inverted dark

The two themes are given opposite depth relationships on purpose. In light the
skills band is **recessed** — a mineral plate below the paper canvas. In dark it
is **raised** off a near-black canvas. Light gets a violet aurora washing the
first screen and clean shadow depth on the panels; dark gets restrained glow and
tonal steps. Two new primitive pairs carry it, `--facet-{light,dark}-band` and
`--facet-{light,dark}-plate`, declared beside the existing ones and consumed
through `--facet-band` and `--facet-plate`. The corrective pass below extends
that principle to the finale, which was the one place the two themes had been
made to agree.

## Motion

**No new motion was added, and no JavaScript was written for this checkpoint.**
The hero field, the section entry, the ribbons and the card light are the
existing enhancements; the atmosphere, the figures, the finale's shard and the
ribbon centre light are all static paint. There is no new rAF loop, no new
listener and no new observer, so there is nothing new to tear down and the
reduced-motion answer is the one the skin already had — with one rule added, so
that the ribbon's centre light, which exists to say "this is the part that is
moving", is not drawn over a list that is standing still.

## The one renderer change, and why it is here

Making the hero visual larger made it **two and a quarter times more expensive
every frame**, and that was not a theory: with the larger slot, WebKit failed
the accepted Satoshi Run relaunch gate five times in eight runs, where the
pre-change page passed thirty-nine tests out of thirty-nine.

The cause is worth stating precisely, because it is a trap the next
composition change can fall into as well. The shader is two Voronoi passes —
thirty-four hashed cells per fragment, with a sine per cell. PORT-99 measured
it at 0.008 ms of main thread per frame and chose it on that number, and that
number is true; but it is the *main thread's* share. The fragments themselves
belong to the GPU, and on a machine with no GPU worth the name they belong to
the CPU. So a composition that wants a larger visual silently buys a
proportionally larger per-frame bill, and pays it on exactly the machines least
able to afford it.

The fix is a budget rather than a smaller visual: `hero.ts` now caps the
backing store at `MAX_PIXELS = 154_880` — 352 × 440, the accepted visual's own
footprint at a device-pixel ratio of one — and lets the compositor scale the
result up. A slot that size renders exactly as it always did. A larger slot
renders into the same budget, which costs nothing extra per frame and is
invisible: the field is low-frequency and its seams are feathered over three
per cent of the shorter side, so they are tens of pixels wide before any
scaling and stay soft after it. The live hero screenshots in the report are the
evidence for the "invisible" half.

After the budget, WebKit passed that gate eight times out of eight, in half the
wall-clock time the *pre-change* page took.

Two honest consequences, both deliberate:

- On a high-density display the effect now renders into 154,880 fragments
  rather than up to 619,520. That is a real reduction against accepted
  behaviour, taken knowingly: it is the same change that stops a phone
  shading six hundred thousand Voronoi fragments a frame for a decorative
  gradient.
- The slot's size and the effect's cost are now independent, which is the
  property that made this checkpoint's composition affordable at all.

## What was deliberately not done

- **No new dependency.** No Three.js, no animation library, no icon set. The
  figures, the atmosphere and the finale's shard are gradients and borders.
- **No editorial rewriting.** Every word on the page is either a canonical
  Content value or the skin chrome that was already there. The decorative index
  numerals — `01`…`04` — are the only new text, they number positions in a list
  this template composed, and every one of them is `aria-hidden`.
- **The GitHub profile link was left off the finale.** `Profile::links()` is
  canonical and putting it there would have made the ending fuller. It is
  content the home page does not currently carry, and adding content is
  PORT-130's decision, not this checkpoint's. Recorded as an editorial
  opportunity rather than taken.
- **Satoshi Run was not touched.** Not the gesture, not the chunk, not the
  game, not its design. The five-click regression evidence is in the report.
- **No test was rewritten to fit the redesign.** All 911 PHPUnit tests and all
  82 end-to-end tests in three engines pass against the composition as built.
  That was a constraint on the design, not a result of it: the accepted
  contracts — one H1, one anchor per card, the ordered list under `journey`, a
  ribbon per canonical category, nothing hidden pending a script — are the
  reason the page is still a document before it is a composition.

## Corrective pass — the four findings from human review

The composition above was reviewed and approved. The review returned four
visual and responsive defects, corrected without reopening any decision on this
page. Evidence: `docs/reports/PORT-136/corrective/`.

**F1 — the marquee had a visible rail.** Each skills row carried the centre
light at full strength at rest, which painted a differently-toned strip the
full height of the row behind the pills. It read as a seam between every pill,
in dark and unmistakably in light. The light is now `opacity: 0` at rest and
`1` on `:hover` (inside `@media (hover: hover)`) or `:focus-within` — the same
gradient, spending its contrast on an interaction instead of on the page's
resting state. Nothing about the ribbon mechanism moved: the strip still
travels one repeat, the clones are still the runtime's, hover still pauses it,
and reduced motion still switches the light off entirely.

**F2 — section 04 was dark in light mode.** The plate no longer re-points any
semantic colour. Every colour on it is now the ambient token, so each theme
states its own ending. In dark those tokens *are* the dark ones, which is why
the dark plate is byte-identical to the accepted frame. In light the plate is
violet paper — `--facet-light-plate: #dcd8f6`, the one saturated surface on a
route otherwise set on cool grey, as far above the canvas as the recessed
skills band is below it — carrying the light ink, the light accent on the
action, its own atmosphere and its own shard. The atmosphere's density became a
primitive pair of its own, `--facet-{light,dark}-plate-{wash,glow}`: light
carries dark ink and cannot take dark's density behind its words. Dark keeps
the values it had.

**F3 — the mobile footer repeated the whole navigation.** Below the existing
mobile-navigation breakpoint (`47.99em`) the footer's link list is
`display: none`, so it is neither seen nor tabbable, leaving the brand alone on
a balanced line. It is gated on `[data-facet='ready']`: the footer exists so a
reader at the bottom of a long page has a way out that needs no JavaScript, and
without the runtime the header still shows a complete, always-visible list and
this rule never applies. The markup is untouched — all four links are still
served, which is what `ShellStructureTest` asserts — and the desktop footer is
untouched.

**F4 — the mobile Menu control was a pill.** Below the same breakpoint the
visible word is dropped and the button is a 2.75rem (44px) square around the
glyph. The name survives the word: the button carries `aria-label="Menu"` as
well as its text, so its accessible name is still exactly `Menu` — which is
what the end-to-end suite addresses it by — and `aria-expanded`,
`aria-controls`, Escape and focus return are all as they were. The theme
control was left alone, deliberately: the finding was about the menu.
