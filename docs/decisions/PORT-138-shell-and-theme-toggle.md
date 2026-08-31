# PORT-138 — the global shell, and a theme control that shows the theme

**Status:** implemented, awaiting human visual acceptance
**Date:** 2026-08-31
**Scope:** the shell header and footer, the light/dark control, and the
shell-scoped half of the evolving-interface stylesheet. One home-page rule is
included — the skill ribbons' entry mask — and argued for below. Nothing here
reaches content, routing, SEO, auth, contact, the database, the hero renderer,
Satoshi Run, or internationalisation, and no package was added.

---

## What was wrong

Four things, of very different sizes.

**The theme control was a sentence.** It rendered as a pill reading
`[◐] Dark theme`, which after PORT-136 had become the loudest piece of prose in
the header — a header that now sits above sections set in six-rem display type.
It also explained itself twice over: a control whose entire job is to swap two
colour schemes was spending a word, an icon and eighty pixels telling you it
was going to do that.

**The header had been outgrown.** Nothing about it was wrong in kind. It was
simply built to the proportions of the page that used to be underneath it, and
that page was replaced. At 4.75rem tall with 0.875rem nav links it read as a
strip the document had grown past rather than as its top edge.

**The skill ribbons were being eaten by their own labels.** From 52rem the
category name moves out of the row and into a column beside it, so the strip
begins immediately to the right of the word. The strip's mask used one fade
length for both edges — `clamp(2rem, 8vw, 9rem)`, tuned for the *exit*, where a
long ramp is what sells "this list continues past the screen". At the entry
edge the same nine rem held the first two or three chips permanently at part
opacity, right beside `CERTIFICATION` and `LANGUAGE`. The effect was not a
strip emerging from behind a label. It was a label standing on the chips.

**The footer was the header again.** On a wide screen it rendered the brand on
the left and Home / Projects / About / Contact on the right — the same words in
the same order with the same alignment as the banner, at the other end of the
document. A reader who scrolled to the bottom was handed a second copy of the
navigation that had been pinned above them the whole way down.

---

## The theme control

### What it is

A 52 × 28 capsule containing a sky, clouds, stars, and one body that crosses
between the two ends of a track — a sun at the day end, a moon at the night
end. It is adapted from a Figma community component
(`TOGGLE BUTTON — LIGHT AND DARK MODE`, component set `3:187`), which supplies
the visual vocabulary and the palette: `#117AF5` day sky, `#252D37` night sky,
`#ECCD2D` sun, `#C9C9C9` moon, `#889399` craters, white clouds and stars.

Two things about that reference are worth stating plainly, because they bound
what this is.

The original is **369 × 145 px**. That is roughly seven times the width this
header can spend, and most of what the reference does with its space — bitmap
cloud layers, a ray treatment, stacked inner shadows — does not survive being
shrunk to a seventh. What was kept is what still reads at 52px: two skies, two
bodies, a cluster of clouds, five stars, three craters, and exactly three
shadows (one inset highlight, one inset shade, one drop).

And the reference **has no motion**. Its four variants — Sun, Sun_Hover, Moon,
Moon_Hover — carry no prototype links and no reactions; the variant reactions
are empty. There is no source timeline to reproduce and this document does not
claim to have reproduced one. What is here is an interpolation between four
observed still states, tuned to this project's own motion tokens. It is Facet's
version of the idea, not a port of it.

### How it is built

Four spans inside the existing button, and no JavaScript at all:

```
button[data-facet-theme-toggle]      the 44px target, and the state
└── span.facet-theme-toggle__scene   the sky
    ├── span.…__stars                five stars, as background-image
    ├── span.…__clouds               four blobs, as background-image
    └── span.…__orb                  the body; ::after is three craters
```

The clouds, the stars and the craters are layered radial gradients on empty
spans rather than elements — positioned in the same rem grid the capsule is
built on — so the whole scene is paint on four boxes. No canvas, no WebGL, no
animation library, no dependency, no remote asset, and no request. The critical
JavaScript bundle is **byte-identical** before and after.

State is `aria-pressed` on the button, which the theme module already
maintained. Every rule is a CSS transition keyed off that one attribute. There
is no `requestAnimationFrame` loop, no interval, and no new listener.

### What was deliberately not touched

The theme contract. `resources/js/theme.ts` is unmodified;
`views/partials/theme-bootstrap.php` is unmodified. Initial resolution, the
single `facet.theme` localStorage key, the pre-paint stamp on `<html>`, the
system-preference fallback and its `change` listener, `data-theme` on the root,
and the absence of any cookie or round trip are all exactly as they were. The
control is a *view* of that contract. Making the new presentation easier would
have been a bad reason to rewrite state management, and it was not necessary.

### The accessible name, and why it did not change

The brief's preferred naming was an action that flips with the state —
"Switch to light theme" / "Switch to dark theme" — *or the equivalent mechanism
already used by the project*. The project's mechanism is a toggle button:
`aria-pressed`, with a stable name for the thing being toggled. That is the
WAI-ARIA pattern for exactly this control, and the two cannot be combined
coherently: a button announced as "Switch to light theme, pressed" tells a
listener two contradictory things about what pressing it does.

So the name stayed `Dark theme` and `aria-pressed` stayed the state. What
changed is that the word is no longer *visible*: it is clipped out of view by
the stylesheet, which keeps it in the accessibility tree. It is still real
text — not an `aria-label`, not a `title` — and the control has no competing
name. The scene is `aria-hidden` in one place, on the wrapper, so none of the
decoration reaches the tree.

This also keeps `tests/E2E/satoshi-run.spec.ts` and
`tests/E2E/progressive-enhancement.spec.ts` addressing the control by the name
a screen reader announces, which is the property those suites were written to
have.

### State without colour

A control this small cannot spend its legibility on hue. The state is encoded
three times over and only one of them is colour:

- **position** — the body sits at the day end or the night end of the track;
- **shape** — the sun is evenly lit; the moon is lit from one side and has
  three craters;
- **colour** — blue sky and yellow body, or charcoal sky and grey body.

The first two survive greyscale, forced colours, and any form of colour
vision. The E2E suite asserts position and craters directly, and asserts that
the sky changes as well rather than instead.

### Motion

360ms — `--facet-duration-slow`, the token the skin already had, inside the
250–450ms the brief asked for. The body uses `--facet-ease-emphasized`
(`cubic-bezier(0.2, 0.8, 0.2, 1)`), which has the slight overshoot; the flat
layers behind it use `--facet-ease-standard`. One stagger, and only one: the
stars arrive 80ms after everything else, which is what makes the switch read as
a sequence rather than as four things happening at once. The clouds *leave the
frame* downward rather than dissolving where they stand — leaving reads as
weather, fading reads as a bug.

### Reduced motion

The shared layer already collapses every transition to 0.01ms, so nothing here
is about speed; it is about distance. A translation that takes no time is still
a translation, and at zero duration the two vertical ones — clouds falling out,
stars rising in — become layers that teleport. The skin sets
`--facet-toggle-drift: 0` under `prefers-reduced-motion`, which removes that
movement rather than compressing it, and drops the 80ms stagger, which at zero
duration is just 80ms of a visibly half-finished scene.

Everything that carries meaning is untouched. The sky still changes, the body
still moves end to end and still becomes a moon, the craters still appear, and
the theme it switches is identical. The final state is the same in both
preferences.

### Hover

The reference ships a hover variant per state and no information about what it
did with them. This is an adaptation: the body leans 0.14rem toward the middle
and the capsule's drop shadow deepens. Two pixels, deliberately — a body that
moved far enough to look half-switched would make the state ambiguous, which is
the one thing a control whose whole job is reporting a state cannot afford. It
is behind `@media (hover: hover)`, so a touch device never enters a state it
cannot leave.

---

## The header

A rebalance, not a redesign. The route list, the markup, the collapse
behaviour, the brand's home link and the discovery gesture attached to it are
all untouched.

- `min-height` 4.75rem → 5.25rem, block padding `space-3` → `space-4`;
- nav links 0.875rem → 0.9375rem, with slightly wider padding and a hair of
  negative tracking;
- the brand from a fixed `text-lg` to a small fluid clamp;
- the header's inline gap `space-4` → `space-5`, plus a `space-2` margin after
  the theme control so it separates from "Home" instead of reading as a fifth
  item in the list.

The Satoshi Run gesture is attached to `[data-facet-brand]` in `skin.ts`, which
was not modified. Nothing in this pass touches the brand element, its href, its
attributes or its position, and the full Satoshi suite runs green.

---

## The skill ribbons' entry mask

`--facet-ribbon-fade-start` is new, and falls back to `--facet-ribbon-fade`, so
any ribbon that only states the original variable behaves exactly as before.
The home route now states both: the exit keeps its `clamp(2rem, 8vw, 9rem)`,
and the entry becomes `clamp(0.75rem, 1.6vw, 1.5rem)` — roughly one chip's own
width. Enough that no chip begins on a hard edge; short enough that the first
fully readable chip is the first one after the label gutter.

That is the entire change. The mask geometry, and nothing else: the travel
keyframes, the seamless clone arithmetic, the `[data-facet-ribbon='live']`
gate, the pill shadow correction, the centre light, the hold-on-hover pause,
the reduced-motion behaviour and the SSR wrapping list are all untouched, and
`ribbons.ts` was not opened.

---

## The footer

**Decision: the enhanced page has no footer, for now.**

A footer earns its place by carrying what a header cannot — a legal notice, a
privacy statement, an imprint, a colophon. This site has none of those, and
inventing a `© 2026` or a link to a page that does not exist would be worse
than the duplication it replaced, because a footer that lies has to be unpicked
later. Removing only the links would have left the brand alone on a bordered
strip: a smaller fragment of the header, at the bottom of the page, still
carrying nothing new. So the enhanced page ends where its content ends.

Two things about *how* are load-bearing.

It is `display: none` on the landmark rather than a visual hide, because a
visually hidden footer is four links still in the tab order, still announced,
and landing a keyboard reader somewhere they cannot see.

And the gate is `[data-facet='ready']`, which the shared runtime sets only once
the header's collapse is mounted and working. **Without JavaScript the rule
never applies**: the server's footer renders in full, with its four plain
anchors, and a reader at the bottom of a long page with no script running still
has a way out — which is the only circumstance in which repeating the
navigation was ever doing work. The markup, the partial and the architecture
are untouched; one declaration takes it off the enhanced page.

This generalises a rule that already existed below 47.99em, where the same
decision had already been made for the same reasons. It is now stated once.

### The invariant this creates

> When legal, privacy, or any other footer-specific content is introduced, a
> compact real footer must come back — on desktop **and** on mobile — and it
> must not be a repeat of the primary navigation.

Those links are not implemented here and must not be invented to justify a
footer.

---

## Considered and left alone

**Journey.** Inspected and left unchanged. It is denser than the hero and the
work grid, but that density is doing work: the section is four milestones with
every fact the corpus records, and it already has a dedicated tightening pass
below 40rem. The available lever was padding and line-height, and spending it
would have made the section taller without making it more readable. Nothing was
removed, truncated, reordered or collapsed, because nothing was touched.

**The contact finale.** Inspected and left unchanged. The gap between the
statement and the far-right action is wide at 1920 and it is the composition
working as designed — the action is set on the sentence's own baseline and the
shard crosses the space between them. Narrowing it would have traded a
deliberate wide gesture for a safer, smaller one, and the palette, the height
and the identity were all explicitly out of bounds. This is a judgement about
taste and it is the human reviewer's to overturn; the shot at 1920 is in the
evidence directory for exactly that purpose.

Both were optional in the brief, and the primary work — the control — is where
the pass was spent.

---

## Out of scope, and untouched

PORT-137 internationalisation. No FR/EN toggle, no dictionaries, no `/fr` or
`/en`, no `hreflang`, no canonical-locale change, and the existing
French/English mixture is exactly as it was. No content was written, rewritten
or invented anywhere.
